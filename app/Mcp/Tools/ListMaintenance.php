<?php

namespace App\Mcp\Tools;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceTask;
use App\Mcp\Serializer;
use App\Mcp\Tools\Concerns\ValidatesArguments;
use App\Models\Maintenance;
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
 * Maintenance as the list in the panel has it, without the long texts.
 */
#[Name('list_maintenance')]
#[IsReadOnly]
#[IsOpenWorld(false)]
class ListMaintenance extends Tool
{
    use ValidatesArguments;

    private const int DEFAULT_LIMIT = 20;

    private const int MAX_LIMIT = 50;

    public function description(): string
    {
        return 'List maintenance in Herdbook, without its plan and report (get_maintenance has those). '
            .'Status "planned" includes overdue work, planned work whose time has passed; "overdue" is only that; without a status, everything. '
            .'With status planned or overdue the list comes in the order the work is due, otherwise the latest start first. '
            .'server_ids and project_ids (from search_inventory) find maintenance linked directly to any of them. '
            .'At most '.self::MAX_LIMIT.' items (default '.self::DEFAULT_LIMIT.'); "more" says there is more, so narrow it down with the filters.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(self::statuses())->description('Leave it out for everything.'),
            'task' => $schema->string()->enum(MaintenanceTask::class)->description('Only maintenance that includes this work.'),
            'server_ids' => $schema->array()->items($schema->integer())->max(self::MAX_LINKS)->description('Server ids from search_inventory: maintenance linked to any of them.'),
            'project_ids' => $schema->array()->items($schema->integer())->max(self::MAX_LINKS)->description('Project ids from search_inventory: maintenance linked to any of them (not to their servers).'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)->default(self::DEFAULT_LIMIT),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'status' => ['nullable', Rule::in(self::statuses())],
            'task' => ['nullable', Rule::enum(MaintenanceTask::class)],
            ...$this->linkRules(),
            'limit' => ['nullable', 'numeric', 'integer', 'between:1,'.self::MAX_LIMIT],
        ]);

        $status = $input['status'] ?? null;
        $serverIds = $input['server_ids'] ?? [];
        $projectIds = $input['project_ids'] ?? [];
        $limit = (int) ($input['limit'] ?? self::DEFAULT_LIMIT);

        $found = Maintenance::query()
            ->with(Serializer::MAINTENANCE_RELATIONS)
            ->when($status, fn (Builder $query, string $status) => $status === 'overdue'
                ? $query->overdue()
                : $query->where('status', MaintenanceStatus::from($status)))
            ->when($input['task'] ?? null, fn (Builder $query, string $task) => $query->includesTask($task))
            ->when($serverIds !== [] || $projectIds !== [], fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->when($serverIds, fn (Builder $query) => $query->orWhereHas('servers', fn (Builder $servers) => $servers->whereKey($serverIds)))
                ->when($projectIds, fn (Builder $query) => $query->orWhereHas('projects', fn (Builder $projects) => $projects->whereKey($projectIds)))))
            ->when(
                in_array($status, ['planned', 'overdue'], true),
                fn (Builder $query) => $query->orderByDue(),
                fn (Builder $query) => $query->orderByDesc('starts_at'),
            )
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        return Response::structured([
            'maintenance' => $found->take($limit)->map(Serializer::maintenance(...))->values()->all(),
            'more' => $found->count() > $limit,
        ]);
    }

    /**
     * The stored statuses, and "overdue".
     *
     * @return list<string>
     */
    private static function statuses(): array
    {
        return [...array_column(MaintenanceStatus::cases(), 'value'), 'overdue'];
    }
}
