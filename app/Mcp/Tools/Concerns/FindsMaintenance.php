<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Maintenance;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Response;

/**
 * The tools that take one maintenance by its id.
 */
trait FindsMaintenance
{
    use FindsRecords;

    protected function idSchema(JsonSchema $schema): Type
    {
        return $this->idSchemaFrom($schema, 'list_maintenance');
    }

    /**
     * The maintenance with this id, or the answer that there is none.
     */
    protected function findMaintenance(int $id): Maintenance|Response
    {
        return Maintenance::query()->find($id) ?? $this->notFound($id);
    }

    protected function notFound(mixed $id): Response
    {
        return Response::error(__('There is no maintenance with id :id. list_maintenance finds them.', ['id' => $id]));
    }
}
