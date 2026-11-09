<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceTask;
use App\Mcp\Serializer;
use App\Models\Maintenance;
use App\Models\User;
use App\Rules\Moment;
use App\Rules\NoCredentials;
use App\Rules\NoImagesOrHtml;
use App\Rules\NoSecretMaterial;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * What the tools that write maintenance share. The same limits and messages
 * as the forms (MaintenanceSchema), checked on the work as it will be stored;
 * agents write under stricter rules than people: no credentials, no images
 * or HTML. Times are ISO 8601 (App\Rules\Moment).
 */
trait WritesMaintenance
{
    use RequiresWriteAccess, ValidatesArguments;

    /**
     * How many planned maintenances an answer names that share a server or project.
     */
    private const int OTHER_PLANNED = 10;

    /**
     * The fields of work, for the input schema. $partial: a change, in which
     * none is required and null clears what may be empty; otherwise $status
     * says whether a start is required.
     *
     * @return array<string, mixed>
     */
    protected function workSchema(JsonSchema $schema, MaintenanceStatus $status = MaintenanceStatus::Planned, bool $partial = false): array
    {
        $moment = self::momentFormat();
        $start = $schema->string()->description(match (true) {
            $partial => "When it starts or started. {$moment}",
            $status === MaintenanceStatus::Done => "When it started; default now, not in the future. {$moment}",
            default => "When it starts. {$moment}",
        });
        $tasks = $schema->array()->items($schema->string()->enum(MaintenanceTask::class))->min(1)->max(count(MaintenanceTask::cases()))
            ->description('The kinds of work: updates (apt upgrade and the like), reboot, inspection (logged in, read the logs), upgrade (a new OS, PHP or database version), other.');

        return [
            'tasks' => $partial ? $tasks : $tasks->required(),
            'title' => $schema->string()->max(255)->nullable($partial)->description($partial
                ? 'Null for one made from the work, such as "Updates and reboot".'
                : 'Leave it out for one made from the work, such as "Updates and reboot".'),
            'starts_at' => $partial || $status === MaintenanceStatus::Done ? $start : $start->required(),
            'ends_at' => $schema->string()->nullable($partial)->description(match (true) {
                $partial => "When it ends or ended; null for no end. Not before the start. {$moment}",
                $status === MaintenanceStatus::Done => "When it ended, if that matters; not before the start, which is now without starts_at. {$moment}",
                default => "When it ends, for a window; not before the start. {$moment}",
            }),
            'server_ids' => $schema->array()->items($schema->integer())->max(self::MAX_LINKS)->description(($partial ? 'Replaces its servers. ' : '')
                .'From search_inventory; at least one server or project. For "production of a project": the servers of that environment.'),
            'project_ids' => $schema->array()->items($schema->integer())->max(self::MAX_LINKS)->description(($partial ? 'Replaces its projects. ' : '')
                .'From search_inventory, when the work concerns the project as a whole.'),
        ];
    }

    /**
     * The rules of the fields of work. $partial: a change, in which each is
     * optional, but tasks and the start can't be emptied; the tool checks
     * the merged result (assertWork()).
     *
     * @return array<string, mixed>
     */
    protected function workRules(bool $partial = false): array
    {
        return [
            'tasks' => [...($partial ? ['sometimes', 'required'] : ['required']), 'list', 'max:'.count(MaintenanceTask::cases())],
            'tasks.*' => ['required', 'distinct', Rule::enum(MaintenanceTask::class)],
            'title' => ['bail', 'nullable', 'string', 'max:255', new NoSecretMaterial, new NoCredentials],
            'starts_at' => $partial ? ['sometimes', 'required', new Moment] : ['nullable', new Moment],
            'ends_at' => ['nullable', new Moment],
            ...$this->linkRules($partial),
        ];
    }

    /**
     * The plan, for the input schema; $partial: null clears it.
     */
    protected function planSchema(JsonSchema $schema, bool $partial = false): Type
    {
        return $schema->string()->max(Serializer::MAX_TEXT)->nullable($partial)->description('Markdown: the steps, what goes down, how to roll back.'.($partial ? ' Null clears it.' : ''));
    }

    /**
     * The report, for the input schema; $partial: null clears it.
     */
    protected function reportSchema(JsonSchema $schema, bool $partial = false): Type
    {
        return $schema->string()->max(Serializer::MAX_TEXT)->nullable($partial)->description('Markdown: what was done, what stood out, what is still to do.'.($partial ? ' Null clears it; given, it replaces the one there is.' : ''));
    }

    /**
     * How an agent gives a moment, for descriptions.
     */
    protected static function momentFormat(): string
    {
        return 'ISO 8601: 2026-11-12T22:00 (in '.config('herdbook.display_timezone').'), or with an offset (Z, +01:00).';
    }

    /**
     * Markdown that agents may write: what MAX_TEXT allows (first: the other
     * checks take time on a long text), no private keys or credentials, no
     * images or HTML.
     *
     * @return list<mixed>
     */
    protected function textRules(): array
    {
        return ['bail', 'nullable', 'string', 'max:'.Serializer::MAX_TEXT, new NoSecretMaterial, new NoCredentials, new NoImagesOrHtml];
    }

    /**
     * The fields of work present in $input as they will be stored: the title
     * tidied, the moments in UTC.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function parseWork(array $input): array
    {
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = Moment::parse($input[$field]);
            }
        }

        if (array_key_exists('title', $input)) {
            $input['title'] = filled($input['title']) ? Str::squish($input['title']) : null;
        }

        return $input;
    }

    /**
     * The work as a whole, as it will be stored: moments within what the
     * columns hold, done work not in the future, no end before the start,
     * and (when its links are given) linked to at least one server or
     * project. The rules and words of the forms.
     *
     * @param  array<string, mixed>  $work  starts_at, ends_at (CarbonImmutable or null), and server_ids and project_ids if they are checked
     */
    protected function checkWork(Validator $validator, array $work, MaintenanceStatus $status): void
    {
        if (array_key_exists('server_ids', $work) && blank($work['server_ids']) && blank($work['project_ids'] ?? null)) {
            $validator->errors()->add('server_ids', __('Link it to at least one server or project: server_ids or project_ids, from search_inventory.'));
        }

        $outOfRange = fn (?CarbonImmutable $moment): bool => $moment !== null
            && ($moment->lt(Maintenance::earliestMoment()) || $moment->gt(Maintenance::latestMoment()));

        foreach (['starts_at', 'ends_at'] as $field) {
            if ($outOfRange($work[$field] ?? null)) {
                $validator->errors()->add($field, __('Pick a moment between 1970 and 2038.'));
            }
        }

        $start = $outOfRange($work['starts_at'] ?? null) ? null : ($work['starts_at'] ?? null);
        $end = $outOfRange($work['ends_at'] ?? null) ? null : ($work['ends_at'] ?? null);

        if ($status === MaintenanceStatus::Done && $start?->isFuture()) {
            $validator->errors()->add('starts_at', __('Work that has been done cannot be in the future.'));
        }

        if ($start !== null && $end !== null && $end->lt($start)) {
            $validator->errors()->add('ends_at', __('The end cannot be before the start.'));
        }
    }

    /**
     * checkWork() outside a validation: throws, so a transaction around it
     * is rolled back and the agent gets the messages.
     *
     * @param  array<string, mixed>  $work
     */
    protected function assertWork(array $work, MaintenanceStatus $status): void
    {
        $validator = ValidatorFactory::make([], []);
        $this->checkWork($validator, $work, $status);

        if ($validator->errors()->isNotEmpty()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * New work, validated and read. Done work starts now unless it says
     * otherwise; planned work needs its start.
     *
     * @param  array<string, mixed>  $rules  for the text the tool takes
     * @return array<string, mixed>
     */
    protected function validatedNewWork(Request $request, array $rules, MaintenanceStatus $status): array
    {
        $now = CarbonImmutable::now();
        $rules = [...$this->workRules(), ...$rules];

        if ($status === MaintenanceStatus::Planned) {
            $rules['starts_at'] = ['required', new Moment];
        }

        $work = function (array $input) use ($now): array {
            $parsed = $this->parseWork($input);

            return [
                ...$parsed,
                'starts_at' => $parsed['starts_at'] ?? $now,
                'server_ids' => $input['server_ids'] ?? [],
                'project_ids' => $input['project_ids'] ?? [],
            ];
        };

        $input = $this->validated($request, $rules, after: fn (Validator $validator) => $this->checkWork($validator, $work($validator->getData()), $status));

        return $work($input);
    }

    /**
     * Creates it with its links in one go, written down by the account of
     * the agent and recorded through it.
     *
     * @param  array<string, mixed>  $work  parsed (parseWork()), with server_ids and project_ids
     * @param  array<string, mixed>  $attributes  the rest: the plan or the report
     */
    protected function record(Request $request, MaintenanceStatus $status, array $work, array $attributes): Maintenance
    {
        return DB::transaction(function () use ($request, $status, $work, $attributes): Maintenance {
            $user = $request->user();

            $maintenance = new Maintenance([
                'tasks' => $work['tasks'],
                'title' => $work['title'] ?? null,
                'status' => $status,
                'starts_at' => $work['starts_at'],
                'ends_at' => $work['ends_at'] ?? null,
                ...$attributes,
            ]);
            $maintenance->forceFill([
                'user_id' => $user?->getAuthIdentifier(),
                'recorded_via' => $user instanceof User ? $user->currentAgentToken()?->name : null,
            ])->save();

            $maintenance->servers()->attach(self::ids($work['server_ids']));
            $maintenance->projects()->attach(self::ids($work['project_ids']));

            return $maintenance;
        });
    }

    /**
     * The stored maintenance (times with their offset: a timezone mistake
     * shows at once), and the planned maintenance it shares a server or
     * project with: it may have been planned already, or be the one to
     * complete instead.
     */
    protected function answer(Maintenance $maintenance): ResponseFactory
    {
        $maintenance->load(Serializer::MAINTENANCE_RELATIONS);
        $servers = $maintenance->servers->modelKeys();
        $projects = $maintenance->projects->modelKeys();

        $others = Maintenance::query()
            ->planned()
            ->whereKeyNot($maintenance->getKey())
            ->where(fn (Builder $query) => $query
                ->whereHas('servers', fn (Builder $linked) => $linked->whereKey($servers))
                ->orWhereHas('projects', fn (Builder $linked) => $linked->whereKey($projects)))
            ->with(Serializer::MAINTENANCE_RELATIONS)
            ->orderByDue()
            ->orderByDesc('id')
            ->limit(self::OTHER_PLANNED)
            ->get();

        return Response::structured([
            'maintenance' => Serializer::maintenance($maintenance),
            'other_planned' => $others->map(Serializer::maintenance(...))->all(),
        ]);
    }

    /**
     * What a description of a new maintenance tells an agent to check first.
     */
    protected static function checkFirst(): string
    {
        return 'Check first with list_maintenance (status planned, the same server_ids or project_ids) whether the work is already there, and if it is, change that one with update_maintenance: nothing written can be deleted by an agent. ';
    }

    /**
     * What a description tells an agent about the texts it writes.
     */
    protected static function textsSentence(string $what = 'A plan or report'): string
    {
        return $what.' is markdown of at most '.number_format(Serializer::MAX_TEXT).' characters, and refuses passwords, tokens, private keys, images and HTML (put tags and placeholders between backticks). ';
    }

    /**
     * What a description tells an agent about the answer.
     */
    protected static function answerSentence(): string
    {
        return 'The answer has the maintenance as stored, with its "version", and in "other_planned" up to '.self::OTHER_PLANNED.' planned maintenances it shares a server or project with, in the order they are due.';
    }

    /**
     * @param  array<int|string>  $ids
     * @return list<int>
     */
    protected static function ids(array $ids): array
    {
        return array_values(array_unique(array_map(intval(...), $ids)));
    }
}
