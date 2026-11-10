<?php

namespace App\Mcp\Tools;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Mcp\Serializer;
use App\Mcp\Tools\Concerns\WritesIncidents;
use App\Models\Incident;
use App\Rules\Moment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

/**
 * An incident written down by an agent, open, as "New incident" in the
 * panel: its author and agent come from RecordsAuthor.
 */
#[Name('open_incident')]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class OpenIncident extends Tool
{
    use WritesIncidents;

    public function description(): string
    {
        return 'Open an incident in Herdbook: an outage or problem that is going on, or was, so what happens and what is done gets written down. '
            .'Check first with list_incidents (the same server_ids or project_ids) whether there is an unresolved one already: Herdbook\'s checks open one themselves when a critical production check stays down. If there is, add to it with add_timeline_entry instead: nothing written can be deleted by an agent. '
            .'Severity sev1 only when production is down or data is at risk; sev2, the default, when it is serious but has a workaround or a limited reach; sev3 for a small problem. '
            .'Link it to the servers and projects it concerns (ids from search_inventory; at least one). '
            .'The title and the impact are plain text and refuse passwords, tokens, private keys and masks such as [address of app-1]. '.self::textsSentence('The report', replacing: false)
            .self::repeatSentence('arguments').self::answerSentence();
    }

    public function schema(JsonSchema $schema): array
    {
        $moment = self::momentFormat();

        return [
            'title' => $schema->string()->max(255)->required()->description('What is wrong, in a few words, such as "Uploads fail on the shop".'),
            'severity' => $schema->string()->enum(IncidentSeverity::class)->default(IncidentSeverity::Sev2->value),
            'started_at' => $schema->string()->description("When it started, if known; default when it was detected. Not in the future. {$moment}"),
            'detected_at' => $schema->string()->description("When it was noticed; default now. Not before the start. {$moment}"),
            'occurred_on' => $schema->string()->format('date')->description('YYYY-MM-DD: the day it is filed under; default the day it started.'),
            'impact' => $schema->string()->max(Serializer::MAX_TEXT)->description('Plain text: who notices what, and for how long.'),
            'report' => $schema->string()->max(Serializer::MAX_TEXT)->description('Markdown: what is known so far. What happens next goes in timeline entries.'),
            'server_ids' => $this->linkSchema($schema, 'From search_inventory; at least one server or project.'),
            'project_ids' => $this->linkSchema($schema, 'From search_inventory: the projects that are affected.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $now = CarbonImmutable::now()->startOfMinute();
        $moments = function (array $input) use ($now): array {
            $detected = self::moment($input['detected_at'] ?? null) ?? $now;

            return ['started_at' => self::moment($input['started_at'] ?? null) ?? $detected, 'detected_at' => $detected];
        };

        $input = $this->validated($request, [
            'title' => ['required', ...$this->plainTextRules(255)],
            'severity' => ['nullable', Rule::enum(IncidentSeverity::class)],
            'started_at' => ['nullable', new Moment],
            'detected_at' => ['nullable', new Moment],
            'occurred_on' => ['nullable', ...self::dayRules()],
            'impact' => $this->plainTextRules(Serializer::MAX_TEXT),
            'report' => $this->textRules(),
            ...$this->linkRules(),
        ], after: function (Validator $validator) use ($moments): void {
            $data = $validator->getData();

            if (blank($data['server_ids'] ?? null) && blank($data['project_ids'] ?? null)) {
                $validator->errors()->add('server_ids', __('Link it to at least one server or project: server_ids or project_ids, from search_inventory.'));
            }

            $given = $moments($data);
            $this->checkMoments($validator, $given, $given['started_at'], ['detected_at']);
        });

        $given = $moments($input);
        $serverIds = self::ids($input['server_ids'] ?? []);
        $projectIds = self::ids($input['project_ids'] ?? []);
        $attributes = [
            'title' => Str::squish($input['title']),
            'severity' => IncidentSeverity::tryFrom($input['severity'] ?? '') ?? IncidentSeverity::Sev2,
            'impact' => $input['impact'] ?? null,
            'body' => $input['report'] ?? null,
        ];
        // The moments it was given: those it wasn't are the call's own.
        $fixed = array_intersect_key($given, array_filter([
            'started_at' => isset($input['started_at']) || isset($input['detected_at']),
            'detected_at' => isset($input['detected_at']),
        ]));
        $occurredOn = $input['occurred_on'] ?? $given['started_at']->timezone(config('herdbook.display_timezone'))->toDateString();

        return self::once(
            Incident::query(),
            [
                ...$attributes,
                ...array_map(fn (CarbonImmutable $moment): string => $moment->toIso8601String(), $fixed),
                'occurred_on' => $input['occurred_on'] ?? null,
                'server_ids' => collect($serverIds)->sort()->values()->all(),
                'project_ids' => collect($projectIds)->sort()->values()->all(),
            ],
            fn (): Incident => DB::transaction(function () use ($attributes, $given, $occurredOn, $serverIds, $projectIds): Incident {
                $incident = Incident::query()->create([
                    ...$attributes,
                    'status' => IncidentStatus::Open,
                    'occurred_on' => $occurredOn,
                    'started_at' => $given['started_at'],
                    'detected_at' => $given['detected_at'],
                ]);

                $incident->servers()->attach($serverIds);
                $incident->projects()->attach($projectIds);

                return $incident;
            }),
            fn (Incident $incident, bool $unchanged) => $this->answer($incident, unchanged: $unchanged),
        );
    }
}
