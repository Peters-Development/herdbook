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
 * Work that is done, written down afterwards, as "Log maintenance" in the panel.
 */
#[Name('log_maintenance')]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class LogMaintenance extends Tool
{
    use WritesMaintenance;

    public function description(): string
    {
        return 'Write down maintenance that has been done: the work, when it started (default now; not in the future), what was done in markdown, and the servers and projects it concerns (ids from search_inventory; at least one). '
            .'One maintenance may cover many servers. '
            .self::textsSentence('The report')
            .self::checkFirst('complete that one with complete_maintenance instead').self::answerSentence();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->workSchema($schema, MaintenanceStatus::Done),
            'report' => $this->reportSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $work = $this->validatedNewWork($request, ['report' => $this->textRules()], MaintenanceStatus::Done);

        return $this->answer($this->record($request, MaintenanceStatus::Done, $work, ['report' => $work['report'] ?? null]));
    }
}
