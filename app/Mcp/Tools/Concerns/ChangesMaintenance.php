<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Maintenance;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Response;

/**
 * The tools that change maintenance that is there: they take it by its id,
 * and refuse to save over what changed since the agent read it (its
 * version) or what it couldn't read whole.
 */
trait ChangesMaintenance
{
    use FindsMaintenance, WritesMaintenance;

    protected function versionSchema(JsonSchema $schema, string $when): Type
    {
        return $this->versionSchemaFrom($schema, $when, 'get_maintenance, list_maintenance');
    }

    /**
     * Changed by someone else since the agent read it: completed, cancelled,
     * or given another plan; its version no longer matches.
     */
    protected function changedSince(Maintenance $maintenance): Response
    {
        return Response::error(__('This maintenance changed since you read it (now: :status). Read it again with get_maintenance.', ['status' => $maintenance->status->value]));
    }

    /**
     * The plan and report given, on the locked row (WritesTexts::checkTexts()).
     *
     * @param  array<string, mixed>  $changes
     */
    protected function checkPlanAndReport(Maintenance $maintenance, array $changes): void
    {
        $this->checkTexts($maintenance, $changes, ['plan' => 'plan', 'report' => 'report']);
    }
}
