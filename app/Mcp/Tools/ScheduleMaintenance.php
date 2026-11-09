<?php

namespace App\Mcp\Tools;

use App\Enums\MaintenanceStatus;
use App\Mcp\Tools\Concerns\WritesMaintenance;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

/**
 * Work planned ahead, as "Schedule maintenance" in the panel.
 */
#[Name('schedule_maintenance')]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ScheduleMaintenance extends Tool
{
    use WritesMaintenance;

    public function description(): string
    {
        return 'Plan maintenance in Herdbook: the work, when it starts (and ends, for a window), a work plan in markdown, and the servers and projects it concerns (ids from search_inventory; at least one). '
            .self::textsSentence('The plan')
            .self::checkFirst().self::answerSentence();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->workSchema($schema, MaintenanceStatus::Planned),
            'plan' => $this->planSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $work = $this->validatedNewWork($request, ['plan' => $this->textRules()], MaintenanceStatus::Planned);

        return $this->answer($this->record(MaintenanceStatus::Planned, $work, ['plan' => $work['plan'] ?? null]));
    }
}
