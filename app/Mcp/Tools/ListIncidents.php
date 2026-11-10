<?php

namespace App\Mcp\Tools;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Mcp\Serializer;
use App\Mcp\Tools\Concerns\ValidatesArguments;
use App\Models\Incident;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Incidents as the list in the panel has them, without their texts and
 * timeline. A project's incidents reach as far as ProjectIncidents says.
 */
#[Name('list_incidents')]
#[IsReadOnly]
#[IsOpenWorld(false)]
class ListIncidents extends Tool
{
    use ValidatesArguments;

    private const int DEFAULT_LIMIT = 20;

    private const int MAX_LIMIT = 50;

    public function description(): string
    {
        return 'List incidents in Herdbook (outages and problems), without their texts and timeline (get_incident has those). '
            .'Status "unresolved", the default, is open and monitoring (it recovered and is being watched); "any" is everything. '
            .'Unresolved ones come open first, then by severity (sev1 is critical); otherwise the latest first. '
            .'project_ids (from search_inventory) find the incidents of those projects as Herdbook counts them: linked to the project, to a server its production needs, or to such a server\'s network. server_ids find incidents linked to any of those servers directly. '
            .'since (YYYY-MM-DD) leaves out incidents that occurred before that day. '
            .'At most '.self::MAX_LIMIT.' items (default '.self::DEFAULT_LIMIT.'); "more" says there is more, so narrow it down with the filters.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(self::statuses())->default('unresolved')->description('Default unresolved: open and monitoring. "any" for everything.'),
            'severity' => $schema->string()->enum(IncidentSeverity::class)->description('Only incidents of this severity.'),
            'server_ids' => $this->linkSchema($schema, 'Server ids from search_inventory: incidents linked to any of them directly.'),
            'project_ids' => $this->linkSchema($schema, 'Project ids from search_inventory: the incidents of any of them, through the servers their production needs too.'),
            'since' => $schema->string()->format('date')->description('YYYY-MM-DD: incidents that occurred on or after that day.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)->default(self::DEFAULT_LIMIT),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'status' => ['nullable', Rule::in(self::statuses())],
            'severity' => ['nullable', Rule::enum(IncidentSeverity::class)],
            ...$this->linkRules(),
            'since' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'numeric', 'integer', 'between:1,'.self::MAX_LIMIT],
        ]);

        $status = $input['status'] ?? 'unresolved';
        $serverIds = array_map(intval(...), $input['server_ids'] ?? []);
        $projectIds = array_map(intval(...), $input['project_ids'] ?? []);
        $limit = (int) ($input['limit'] ?? self::DEFAULT_LIMIT);

        $found = Incident::query()
            ->with(Serializer::INCIDENT_RELATIONS)
            ->when($status === 'unresolved', fn (Builder $query) => $query->unresolved())
            ->when(! in_array($status, ['unresolved', 'any'], true), fn (Builder $query) => $query->where('status', IncidentStatus::from($status)))
            ->when($input['severity'] ?? null, fn (Builder $query, string $severity) => $query->where('severity', $severity))
            ->when($input['since'] ?? null, fn (Builder $query, string $since) => $query->where('occurred_on', '>=', $since))
            ->when($serverIds !== [] || $projectIds !== [], fn (Builder $query) => $query->concerning($serverIds, $projectIds))
            ->when(
                in_array($status, ['unresolved', 'open', 'monitoring'], true),
                fn (Builder $query) => $query->orderByUrgency(),
                fn (Builder $query) => $query->orderByDesc('occurred_on')->orderByDesc('id'),
            )
            ->limit($limit + 1)
            ->get();

        return Response::structured([
            'incidents' => $found->take($limit)->map(Serializer::incident(...))->values()->all(),
            'more' => $found->count() > $limit,
        ]);
    }

    /**
     * The stored statuses, "unresolved" (open and monitoring) and "any".
     *
     * @return list<string>
     */
    private static function statuses(): array
    {
        return ['unresolved', ...array_column(IncidentStatus::cases(), 'value'), 'any'];
    }
}
