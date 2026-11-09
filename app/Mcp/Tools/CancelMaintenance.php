<?php

namespace App\Mcp\Tools;

use App\Enums\MaintenanceStatus;
use App\Mcp\Tools\Concerns\ChangesMaintenance;
use App\Models\Maintenance;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

/**
 * Planned work that will not happen, as "Call off" in the panel.
 */
#[Name('cancel_maintenance')]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld(false)]
class CancelMaintenance extends Tool
{
    use ChangesMaintenance;

    public function description(): string
    {
        return 'Cancel planned maintenance that will not happen. Give its "version" to be sure nobody changed it since (refused then). '
            .'Called again on maintenance that is cancelled, it returns it; maintenance that is done can\'t be cancelled. A person can set it back to planned in Herdbook. '
            .self::answerSentence();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $this->idSchema($schema),
            'version' => $this->versionSchema($schema, 'Its "version" from get_maintenance or list_maintenance.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $this->validated($request, ['id' => $this->idRules(), 'version' => ['nullable', 'string', 'max:64']]);
        $maintenance = $this->findMaintenance((int) $input['id']);

        if (! $maintenance instanceof Maintenance) {
            return $maintenance;
        }

        $cancelled = $maintenance->cancel($input['version'] ?? null);

        return match (true) {
            $cancelled => $this->answer($maintenance),
            ! $maintenance->exists => $this->notFound($maintenance->getKey()),
            // Cancelled already: called again, it gets the same answer.
            $maintenance->status === MaintenanceStatus::Cancelled => $this->answer($maintenance),
            $maintenance->status === MaintenanceStatus::Planned => $this->changedSince($maintenance),
            default => Response::error(__('This maintenance is done and can\'t be cancelled.')),
        };
    }
}
