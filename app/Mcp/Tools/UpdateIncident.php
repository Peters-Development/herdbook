<?php

namespace App\Mcp\Tools;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Mcp\Serializer;
use App\Mcp\Tools\Concerns\ChangesIncident;
use App\Models\Incident;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

/**
 * An incident changed, as the edit form in the panel does: only what the
 * agent gives, only if nobody changed it since the agent read it, checked
 * as a whole on the row as it is when it is saved.
 */
#[Name('update_incident')]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld(false)]
class UpdateIncident extends Tool
{
    use ChangesIncident;

    public function description(): string
    {
        return 'Change an incident: its title, severity, status (open, monitoring or resolved; resolving sets resolved_at to now unless you give it, as resolve_incident does, which also takes the root cause), the day it is filed under, when it started, was detected and was resolved, its impact, root cause and report, or the servers and projects it concerns. '
            .'Read it first with get_incident and give its "version": as this overwrites what people, monitoring or other agents wrote, the change is refused when someone changed it since. '
            .'Give only what changes; what you leave out stays, and an empty string counts as left out. Null clears a time, the impact, the root cause or the report; server_ids or project_ids replace the links of that kind (its domains, networks and clients stay; one server or project at least). Reopened (status open or monitoring) it has no resolution time any more; resolved_at is refused for an incident that isn\'t, or doesn\'t become, resolved. '
            .'A text you read with masks such as [address of app-1] can\'t be written back with them: write one of your own. '
            .self::cutTextsSentence('An impact, root cause or report', 'get_incident')
            .self::textsSentence('A root cause or report').'The impact is plain text. '
            .self::answerSentence();
    }

    public function schema(JsonSchema $schema): array
    {
        $moment = self::momentFormat();

        return [
            'id' => $this->idSchema($schema),
            'version' => $this->versionSchema($schema, 'always needed.')->required(),
            'title' => $schema->string()->max(255),
            'severity' => $schema->string()->enum(IncidentSeverity::class),
            'status' => $schema->string()->enum(IncidentStatus::class),
            'occurred_on' => $schema->string()->format('date')->description('YYYY-MM-DD: the day it is filed under.'),
            'started_at' => $schema->string()->nullable()->description("Not in the future. {$moment}"),
            'detected_at' => $schema->string()->nullable()->description("Not before the start, not in the future. {$moment}"),
            'resolved_at' => $schema->string()->nullable()->description("Not before the start, not in the future; follows the status unless given: none when reopened, now when resolved without one. {$moment}"),
            'impact' => $schema->string()->max(Serializer::MAX_TEXT)->nullable()->description('Plain text: who notices what. Null clears it.'),
            'root_cause' => $schema->string()->max(Serializer::MAX_TEXT)->nullable()->description('Markdown: why it happened. Null clears it.'),
            'report' => $schema->string()->max(Serializer::MAX_TEXT)->nullable()->description('Markdown: the full write-up. Null clears it.'),
            'server_ids' => $schema->array()->items($schema->integer())->max(self::MAX_LINKS)->description('Replaces its servers; from search_inventory.'),
            'project_ids' => $schema->array()->items($schema->integer())->max(self::MAX_LINKS)->description('Replaces its projects; from search_inventory.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'id' => $this->idRules(),
            'version' => $this->versionRules(required: true),
            'title' => ['sometimes', 'required', ...$this->plainTextRules(255)],
            'severity' => ['sometimes', 'required', Rule::enum(IncidentSeverity::class)],
            'status' => ['sometimes', 'required', Rule::enum(IncidentStatus::class)],
            'occurred_on' => ['sometimes', 'required', ...self::dayRules()],
            'started_at' => self::momentRules(),
            'detected_at' => self::momentRules(),
            'resolved_at' => self::momentRules(),
            'impact' => $this->plainTextRules(Serializer::MAX_TEXT),
            'root_cause' => $this->textRules(linksCheckedOnLockedRow: true),
            'report' => $this->textRules(linksCheckedOnLockedRow: true),
            ...$this->linkRules(partial: true),
        ]);

        $incident = $this->findIncident((int) $input['id']);

        if (! $incident instanceof Incident) {
            return $incident;
        }

        $changes = $this->parseChanges($input);

        $updated = DB::transaction(function () use ($incident, $changes, $input): bool {
            $updated = $incident->saveWhile(fn (): bool => true, function () use ($incident, $changes): array {
                $changes = self::withResolution($incident, $changes);
                $this->checkChanges($incident, $changes);

                return Arr::except($changes, ['server_ids', 'project_ids']);
            }, $input['version']);

            if ($updated) {
                foreach (['server_ids' => 'servers', 'project_ids' => 'projects'] as $argument => $relation) {
                    if (array_key_exists($argument, $changes)) {
                        $incident->{$relation}()->sync(self::ids($changes[$argument]));
                    }
                }
            }

            return $updated;
        }, attempts: 3);

        return match (true) {
            $updated => $this->answer($incident),
            ! $incident->exists => $this->incidentNotFound($incident->getKey()),
            default => $this->changedSince($incident),
        };
    }
}
