<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Project;
use App\Models\Server;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator as ValidatorInstance;
use Laravel\Mcp\Request;

/**
 * The arguments of a tool call, validated. The package hands a tool the raw
 * JSON, without the ConvertEmptyStringsToNull a form passes through, and
 * models send "" for an optional argument they leave empty: so a blank
 * string counts as absent. Values are not trimmed: a plan's markdown may
 * start with an indented line.
 */
trait ValidatesArguments
{
    /**
     * How many servers or projects one argument may name.
     */
    protected const int MAX_LINKS = 50;

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @param  (Closure(ValidatorInstance): void)|null  $after  checks on the valid arguments as a whole
     * @return array<string, mixed>
     */
    protected function validated(Request $request, array $rules, array $messages = [], ?Closure $after = null): array
    {
        $arguments = collect($request->all())
            ->reject(fn (mixed $value): bool => is_string($value) && trim($value) === '')
            ->all();

        // Messages name a parameter as the agent sends it: server_ids, not "server ids".
        $attributes = collect(array_keys($rules))->mapWithKeys(fn (string $key): array => [$key => Str::before($key, '.*')])->all();

        // The arguments themselves first: a rule for each item of an array
        // runs on every item, so an array of a million ids is refused by its
        // size before that.
        $outer = array_filter($rules, fn (string $key): bool => ! str_contains($key, '*'), ARRAY_FILTER_USE_KEY);
        Validator::make($arguments, $outer, $messages, $attributes)->validate();

        $validator = Validator::make($arguments, $rules, $messages, $attributes);

        if ($after !== null) {
            $validator->after(fn (ValidatorInstance $validator) => $validator->errors()->isEmpty() ? $after($validator) : null);
        }

        return $validator->validate();
    }

    /**
     * server_ids and project_ids: lists of existing ids, checked in one query
     * each, with a message that says where to find them.
     *
     * @return array<string, mixed>
     */
    protected function linkRules(): array
    {
        return [
            'server_ids' => ['nullable', 'list', 'max:'.self::MAX_LINKS, $this->existing(Server::class, fn (string $ids): string => __('No server has id :ids. search_inventory finds the ids.', ['ids' => $ids]))],
            'server_ids.*' => ['required', 'integer'],
            'project_ids' => ['nullable', 'list', 'max:'.self::MAX_LINKS, $this->existing(Project::class, fn (string $ids): string => __('No project has id :ids. search_inventory finds the ids.', ['ids' => $ids]))],
            'project_ids.*' => ['required', 'integer'],
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
            if (! is_array($ids) || collect($ids)->contains(fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) === false)) {
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
