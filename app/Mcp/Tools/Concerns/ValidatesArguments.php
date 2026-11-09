<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Project;
use App\Models\Server;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;
use Laravel\Mcp\Request;

/**
 * The arguments of a tool call, validated. The package hands a tool the raw
 * JSON, without the ConvertEmptyStringsToNull a form passes through, and
 * models send "" for an optional argument they leave empty: so a blank
 * string counts as absent. Values are not trimmed: a plan's markdown may
 * start with an indented line. An argument the tool doesn't take is refused,
 * not ignored: a "plan" sent to a tool that takes a "report" would be lost.
 */
trait ValidatesArguments
{
    /**
     * How many servers or projects one argument may name.
     */
    protected const int MAX_LINKS = 50;

    /**
     * @param  array<string, mixed>  $rules
     * @param  (Closure(ValidatorInstance): void)|null  $after  checks on the valid arguments as a whole
     * @return array<string, mixed>
     */
    protected function validated(Request $request, array $rules, ?Closure $after = null): array
    {
        $arguments = collect($request->all())
            ->reject(fn (mixed $value): bool => is_string($value) && trim($value) === '')
            ->all();

        $known = array_values(array_filter(array_keys($rules), fn (string $key): bool => ! str_contains($key, '.')));
        $unknown = array_diff(array_keys($arguments), $known);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['arguments' => __('Unknown argument: :unknown. This tool takes: :known.', [
                'unknown' => Str::limit(implode(', ', $unknown), 100),
                'known' => implode(', ', $known),
            ])]);
        }

        // Messages name a parameter as the agent sends it: server_ids, not "server ids".
        $attributes = collect(array_keys($rules))->mapWithKeys(fn (string $key): array => [$key => Str::before($key, '.*')])->all();

        // The arguments themselves first: a rule for each item of an array
        // runs on every item, so an array of a million ids is refused by its
        // size before that. Then the items, then the arguments as a whole.
        $outer = array_filter($rules, fn (string $key): bool => ! str_contains($key, '*'), ARRAY_FILTER_USE_KEY);
        $validator = Validator::make($arguments, array_diff_key($rules, $outer), [], $attributes);

        if ($after !== null) {
            $validator->after(fn (ValidatorInstance $validator) => $validator->errors()->isEmpty() ? $after($validator) : null);
        }

        try {
            return [...Validator::make($arguments, $outer, [], $attributes)->validate(), ...$validator->validate()];
        } catch (ValidationException $exception) {
            throw self::named($exception);
        }
    }

    /**
     * The package joins the messages into one string without their keys, so
     * a message that doesn't name its argument (one of Herdbook's own, such
     * as "This looks like a password or token") gets it in front: with a
     * title, a plan and a report, the agent would have to guess.
     */
    protected static function named(ValidationException $exception): ValidationException
    {
        $errors = collect($exception->errors())->map(function (array $messages, string $key): array {
            $argument = Str::before($key, '.');

            return array_map(fn (string $message): string => $key === 'arguments' || str_contains($message, $argument) ? $message : "{$argument}: {$message}", $messages);
        });

        return ValidationException::withMessages($errors->all());
    }

    /**
     * The schema of a list of server or project ids, as linkRules() takes it.
     */
    protected function linkSchema(JsonSchema $schema, string $description): Type
    {
        return $schema->array()->items($schema->integer())->max(self::MAX_LINKS)->description($description);
    }

    /**
     * server_ids and project_ids: lists of existing ids, checked in one query
     * each, with a message that says where to find them. $partial: a change,
     * in which a list given replaces the links of its kind, so it may be
     * empty but not null.
     *
     * @return array<string, mixed>
     */
    protected function linkRules(bool $partial = false): array
    {
        $given = $partial ? 'sometimes' : 'nullable';

        return [
            'server_ids' => ['bail', $given, 'list', 'max:'.self::MAX_LINKS, $this->existing(Server::class, fn (string $ids): string => __('No server has id :ids. search_inventory finds the ids.', ['ids' => $ids]))],
            'server_ids.*' => ['required', 'numeric', 'integer', 'distinct'],
            'project_ids' => ['bail', $given, 'list', 'max:'.self::MAX_LINKS, $this->existing(Project::class, fn (string $ids): string => __('No project has id :ids. search_inventory finds the ids.', ['ids' => $ids]))],
            'project_ids.*' => ['required', 'numeric', 'integer', 'distinct'],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  Closure(string): string  $message  for the missing ids, joined
     */
    private function existing(string $model, Closure $message): Closure
    {
        return function (string $attribute, mixed $ids, Closure $fail) use ($model, $message): void {
            // Wrong items have a message of their own (server_ids.*).
            if (! is_array($ids) || collect($ids)->contains(fn (mixed $id): bool => is_bool($id) || filter_var($id, FILTER_VALIDATE_INT) === false)) {
                return;
            }

            $ids = array_map(intval(...), $ids);
            $missing = array_diff($ids, $model::query()->whereKey($ids)->pluck('id')->all());

            if ($missing !== []) {
                $fail($message(implode(', ', $missing)));
            }
        };
    }
}
