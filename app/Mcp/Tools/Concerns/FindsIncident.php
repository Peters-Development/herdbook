<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Incident;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Response;

/**
 * The tools that take one incident by its id.
 */
trait FindsIncident
{
    use FindsRecords;

    protected function idSchema(JsonSchema $schema): Type
    {
        return $this->idSchemaFrom($schema, 'list_incidents');
    }

    /**
     * The incident with this id, or the answer that there is none.
     */
    protected function findIncident(int $id): Incident|Response
    {
        return Incident::query()->find($id) ?? $this->incidentNotFound($id);
    }

    protected function incidentNotFound(mixed $id): Response
    {
        return Response::error(__('There is no incident with id :id. list_incidents finds them.', ['id' => $id]));
    }
}
