<?php

namespace App\Mcp\Tools;

use App\Enums\MaintenanceStatus;
use App\Mcp\Tools\Concerns\ChangesMaintenance;
use App\Models\Maintenance;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

/**
 * Planned or done work changed, as the edit form in the panel does. Only
 * what the agent gives changes, and only if nobody changed it since the
 * agent read it (its version); the result as a whole is checked, on the
 * row as it is when it is saved.
 */
#[Name('update_maintenance')]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld(false)]
class UpdateMaintenance extends Tool
{
    use ChangesMaintenance;

    public function description(): string
    {
        return 'Change maintenance that is planned or done (not cancelled): its work, title, times, plan, report, or the servers and projects it concerns. '
            .'Read it first with get_maintenance and give its "version": as this overwrites what people wrote, the change is refused when someone changed it since. '
            .'Give only what changes; what you leave out stays, and an empty string counts as left out. Null clears ends_at, plan or report, and makes the title from the work again; server_ids or project_ids replace the links of that kind. '
            .self::cutTextsSentence()
            .'To complete planned work use complete_maintenance; to cancel it, cancel_maintenance. '
            .self::textsSentence().self::answerSentence();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $this->idSchema($schema),
            'version' => $this->versionSchema($schema, 'always needed.')->required(),
            ...$this->workSchema($schema, partial: true),
            'plan' => $this->planSchema($schema, partial: true),
            'report' => $this->reportSchema($schema, partial: true),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'id' => $this->idRules(),
            'version' => $this->versionRules(required: true),
            ...$this->workRules(partial: true),
            'plan' => $this->textRules(),
            'report' => $this->textRules(),
        ]);
        $maintenance = $this->findMaintenance((int) $input['id']);

        if (! $maintenance instanceof Maintenance) {
            return $maintenance;
        }

        if ($maintenance->status === MaintenanceStatus::Cancelled) {
            return Response::error(__('This maintenance was called off. Ask a person to set it back to planned in Herdbook.'));
        }

        $changes = Arr::except($this->parseWork($input), ['id', 'version']);
        $status = $maintenance->status;

        $updated = DB::transaction(function () use ($maintenance, $changes, $status, $input): bool {
            $updated = $maintenance->updateWhileStill($status, function () use ($maintenance, $changes, $status): array {
                $this->refuseCutTexts($maintenance, $changes);
                $this->checkMerged($maintenance, $changes, $status);

                return Arr::only($changes, ['tasks', 'title', 'starts_at', 'ends_at', 'plan', 'report']);
            }, $input['version']);

            if ($updated) {
                $this->syncLinks($maintenance, $changes);
            }

            return $updated;
        }, attempts: 3);

        return match (true) {
            $updated => $this->answer($maintenance),
            ! $maintenance->exists => $this->notFound($maintenance->getKey()),
            default => $this->changedSince($maintenance),
        };
    }

    /**
     * The work as it will be, the changes over the current row: its moments,
     * and its links when they change.
     *
     * @param  array<string, mixed>  $changes
     */
    private function checkMerged(Maintenance $maintenance, array $changes, MaintenanceStatus $status): void
    {
        $work = [
            'starts_at' => array_key_exists('starts_at', $changes) ? $changes['starts_at'] : $maintenance->starts_at,
            'ends_at' => array_key_exists('ends_at', $changes) ? $changes['ends_at'] : $maintenance->ends_at,
        ];

        // The links of the locked row: updateWhileStill() loaded them.
        if (array_key_exists('server_ids', $changes) || array_key_exists('project_ids', $changes)) {
            $work['server_ids'] = $changes['server_ids'] ?? $maintenance->servers->modelKeys();
            $work['project_ids'] = $changes['project_ids'] ?? $maintenance->projects->modelKeys();
        }

        $this->assertWork($work, $status);
    }

    /**
     * A list given replaces the links of its kind.
     *
     * @param  array<string, mixed>  $changes
     */
    private function syncLinks(Maintenance $maintenance, array $changes): void
    {
        if (array_key_exists('server_ids', $changes)) {
            $maintenance->servers()->sync(self::ids($changes['server_ids']));
        }

        if (array_key_exists('project_ids', $changes)) {
            $maintenance->projects()->sync(self::ids($changes['project_ids']));
        }
    }
}
