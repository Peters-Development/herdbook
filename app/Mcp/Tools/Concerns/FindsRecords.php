<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * The id a tool takes one record by.
 */
trait FindsRecords
{
    /**
     * @param  string  $from  the tool that lists them
     */
    protected function idSchemaFrom(JsonSchema $schema, string $from): Type
    {
        return $schema->integer()->min(1)->required()->description("From {$from}, or an earlier answer.");
    }

    /**
     * Numeric first: Laravel's integer rule takes JSON true for 1.
     *
     * @return list<string>
     */
    protected function idRules(): array
    {
        return ['required', 'numeric', 'integer', 'min:1'];
    }
}
