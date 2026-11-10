<?php

namespace App\Mcp\Tools;

use App\Enums\IncidentStatus;
use App\Mcp\Serializer;
use App\Mcp\Tools\Concerns\ChangesIncident;
use App\Models\Incident;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

/**
 * An incident that is over: resolved, when (default now), and why.
 */
#[Name('resolve_incident')]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ResolveIncident extends Tool
{
    use ChangesIncident;

    public function description(): string
    {
        return 'Resolve an incident that is over: when it was resolved (default now; not before the start, not in the future), and if you know them its root cause and report. '
            .'Give its "version" from get_incident: refused when someone changed it since, such as monitoring opening it again because a check went down. A root cause or report given (null clears it) replaces the one there is. '
            .self::cutTextsSentence('A root cause or report', 'get_incident')
            .'Called again on an incident that is resolved (by anyone) with only its id and version, or with the same arguments within '.self::REPEAT_MINUTES.' minutes (its answer lost), it returns it with "unchanged": true; anything else is refused: change a resolved incident, or reopen it, with update_incident. '
            .self::textsSentence('A root cause or report').self::answerSentence();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $this->idSchema($schema),
            'version' => $this->versionSchema($schema, 'always needed.')->required(),
            'resolved_at' => $schema->string()->description('When it was resolved; default now. '.self::momentFormat()),
            'root_cause' => $schema->string()->max(Serializer::MAX_TEXT)->nullable()->description('Markdown: why it happened.'),
            'report' => $schema->string()->max(Serializer::MAX_TEXT)->nullable()->description('Markdown: the full write-up.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'id' => $this->idRules(),
            'version' => $this->versionRules(required: true),
            'resolved_at' => ['sometimes', 'required', ...self::momentRules()],
            'root_cause' => $this->textRules(linksCheckedOnLockedRow: true),
            'report' => $this->textRules(linksCheckedOnLockedRow: true),
        ]);

        $incident = $this->findIncident((int) $input['id']);

        if (! $incident instanceof Incident) {
            return $incident;
        }

        $given = $this->parseChanges($input);
        $call = self::callKey('resolve_incident', Arr::except($input, ['version']));

        $resolved = $incident->saveWhile(fn (Incident $current): bool => $current->status !== IncidentStatus::Resolved, function () use ($incident, $given, $call): array {
            $changes = ['status' => IncidentStatus::Resolved->value, 'resolved_at' => $given['resolved_at'] ?? CarbonImmutable::now()->startOfMinute(), ...$given];
            $this->checkChanges($incident, $changes);
            // Rolled back, it isn't resolved: what is remembered then counts for nothing.
            self::rememberCall($call);

            return $changes;
        }, $input['version']);

        return match (true) {
            $resolved => $this->answer($incident),
            ! $incident->exists => $this->incidentNotFound($incident->getKey()),
            // Resolved already: the same call tried again (its answer lost),
            // or one that gives nothing to change, gets the same answer.
            $incident->status === IncidentStatus::Resolved && ($given === [] || Cache::get($call) === true) => $this->answer($incident, unchanged: true),
            $incident->status === IncidentStatus::Resolved => Response::error(__('This incident is resolved already. Change it, or reopen it, with update_incident.')),
            default => $this->changedSince($incident),
        };
    }
}
