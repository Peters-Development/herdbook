<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Serializer;
use App\Models\Maintenance;
use App\Rules\NoNewLinks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;

/**
 * The tools that change maintenance that is there: they take it by its id,
 * and refuse to save over what changed since the agent read it (its
 * version) or what it couldn't read whole.
 */
trait ChangesMaintenance
{
    use FindsMaintenance, WritesMaintenance;

    /**
     * $when: when the tool needs it.
     */
    protected function versionSchema(JsonSchema $schema, string $when): Type
    {
        return $schema->string()->max(64)->description('Its "version", from get_maintenance, list_maintenance or the answer of your last change to it; '.$when.' Refused when someone changed it since.');
    }

    /**
     * @return list<string>
     */
    protected function versionRules(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:64'];
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
     * The plan and report given, on the locked row: not over a text the
     * agent couldn't read whole, and with no links to other sites but those
     * of the text it replaces.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function checkTexts(Maintenance $maintenance, array $changes): void
    {
        $this->refuseCutTexts($maintenance, $changes);

        $validator = Validator::make(
            array_intersect_key($changes, ['plan' => true, 'report' => true]),
            ['plan' => [new NoNewLinks($maintenance->plan)], 'report' => [new NoNewLinks($maintenance->report)]],
        );

        if ($validator->fails()) {
            throw self::named(new ValidationException($validator));
        }
    }

    /**
     * A plan or report longer than an agent reads whole (get_maintenance cuts
     * it) is not overwritten by one: what it didn't see would be lost.
     *
     * @param  array<string, mixed>  $changes
     */
    private function refuseCutTexts(Maintenance $maintenance, array $changes): void
    {
        $messages = [];

        foreach (['plan', 'report'] as $field) {
            if (array_key_exists($field, $changes) && mb_strlen((string) $maintenance->{$field}) > Serializer::MAX_TEXT) {
                $messages[$field] = __('This text is longer than an agent reads whole: change it in Herdbook itself.');
            }
        }

        if ($messages !== []) {
            throw self::named(ValidationException::withMessages($messages));
        }
    }

    /**
     * What a description tells an agent about texts it can't change.
     */
    protected static function cutTextsSentence(): string
    {
        return 'A plan or report that get_maintenance lists in "truncated" can only be changed in Herdbook itself. ';
    }
}
