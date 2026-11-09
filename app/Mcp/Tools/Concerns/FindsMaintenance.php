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
    protected function idSchema(JsonSchema $schema): Type
    {
        return $schema->integer()->min(1)->required()->description('From list_maintenance, or an earlier answer.');
    }

    /**
     * @return list<string>
     */
    protected function idRules(): array
    {
        return ['required', 'integer', 'min:1'];
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
