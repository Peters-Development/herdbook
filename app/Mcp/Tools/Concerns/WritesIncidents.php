<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Serializer;
use App\Models\AgentToken;
use App\Models\Incident;
use App\Rules\Moment;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Validator;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * What the tools that write incidents share: moments that happened (not in
 * the future, within what the columns hold, not before the start), and an
 * answer that names the unresolved incidents sharing a server or project,
 * so an agent joins one rather than opening another.
 */
trait WritesIncidents
{
    use FindsIncident, WritesTexts;

    /**
     * How many unresolved incidents an answer names that concern the same
     * servers or projects.
     */
    private const int OTHER_UNRESOLVED = 10;

    /**
     * How long the same thing written again by the same agent counts as a
     * call tried again after its answer was lost, not a second one.
     */
    private const int REPEAT_MINUTES = 10;

    /**
     * Writes what $write writes, unless this agent made the same call
     * moments ago: then the answer is what that one wrote ("unchanged"), as
     * a call tried again after its answer was lost would write it twice, and
     * an agent can't delete what it wrote. The same call, not the same
     * record: someone may have changed what it wrote since. One call of an
     * agent at a time, so a call tried again while the first still writes
     * finds what that one wrote.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $records  where what it wrote still counts
     * @param  array<string, mixed>  $call  what makes it the same call: what it writes, as stored (compared exactly, not as MySQL compares text)
     * @param  Closure(): TModel  $write
     * @param  Closure(TModel, bool): ResponseFactory  $answer  the record, and whether it was there already
     */
    protected static function once(Builder $records, array $call, Closure $write, Closure $answer): Response|ResponseFactory
    {
        $agent = auth()->id().':'.AgentToken::current()?->getKey();
        $key = 'mcp-call:'.$agent.':'.hash('sha256', $records->getModel()->getTable()."\n".json_encode($call, JSON_THROW_ON_ERROR));

        try {
            [$record, $unchanged] = Cache::lock('mcp-write:'.$agent, 30)
                ->betweenBlockedAttemptsSleepFor(50)
                ->block(10, function () use ($records, $key, $write): array {
                    $id = Cache::get($key);
                    $repeat = $id === null ? null : $records->whereKey($id)->first();

                    if ($repeat !== null) {
                        return [$repeat, true];
                    }

                    $record = $write();
                    Cache::put($key, $record->getKey(), now()->addMinutes(self::REPEAT_MINUTES));

                    return [$record, false];
                });
        } catch (LockTimeoutException) {
            return Response::error(__('Another call of this agent is still writing. Try again in a moment.'));
        }

        return $answer($record, $unchanged);
    }

    /**
     * What a description tells an agent about calling it again.
     *
     * @param  string  $what  what makes it the same
     */
    protected static function repeatSentence(string $what, string $again = ''): string
    {
        return 'Called again with the same '.$what.' within '.self::REPEAT_MINUTES.' minutes (its answer lost), it writes nothing and returns the first with "unchanged": true'.($again === '' ? '' : '; '.$again).'. ';
    }

    /**
     * Moments of an incident: what has happened, so not in the future;
     * within what the columns hold; and none of $after before $start.
     *
     * @param  array<string, ?CarbonImmutable>  $moments
     * @param  list<string>  $after  the keys of $moments that can't lie before $start
     */
    protected function checkMoments(Validator $validator, array $moments, ?CarbonImmutable $start = null, array $after = []): void
    {
        foreach ($moments as $field => $moment) {
            if ($moment === null) {
                continue;
            }

            if ($moment->lt(Moment::earliest()) || $moment->gt(Moment::latest())) {
                $validator->errors()->add($field, __('Pick a moment between 1970 and 2038.'));
            } elseif ($moment->isFuture()) {
                $validator->errors()->add($field, __('It cannot lie in the future.'));
            } elseif ($start !== null && in_array($field, $after, true) && $moment->lt($start)) {
                $validator->errors()->add($field, __('It cannot be before the start.'));
            }
        }
    }

    /**
     * The day an incident is filed under: one that has come, in the display
     * timezone.
     *
     * @return list<string>
     */
    protected static function dayRules(): array
    {
        return ['date_format:Y-m-d', 'after_or_equal:1970-01-01', 'before_or_equal:'.now(config('herdbook.display_timezone'))->toDateString()];
    }

    /**
     * A moment as given, in minutes as the pickers in the panel; null when
     * it wasn't given.
     */
    protected static function moment(mixed $value): ?CarbonImmutable
    {
        return Moment::parse($value)?->startOfMinute();
    }

    /**
     * The incident, and in "other_unresolved" the unresolved incidents that
     * concern any of its servers or projects (as far as Herdbook counts them
     * for a project): one to join instead, or one that is the same outage.
     *
     * @param  array<string, mixed>  $extra  what the tool adds (the entry, the action item)
     */
    protected function answer(Incident $incident, array $extra = [], bool $unchanged = false): ResponseFactory
    {
        $incident->load(Serializer::INCIDENT_RELATIONS);

        $others = Incident::query()
            ->unresolved()
            ->whereKeyNot($incident->getKey())
            ->concerning($incident->servers->modelKeys(), $incident->projects->modelKeys())
            ->with(Serializer::INCIDENT_RELATIONS)
            ->orderByUrgency()
            ->limit(self::OTHER_UNRESOLVED)
            ->get();

        return Response::structured([
            ...$extra,
            'incident' => Serializer::incident($incident),
            'other_unresolved' => $others->map(Serializer::incident(...))->all(),
            ...($unchanged ? ['unchanged' => true] : []),
        ]);
    }

    /**
     * What a description tells an agent about the answer.
     */
    protected static function answerSentence(string $what = ''): string
    {
        return 'The answer has '.($what === '' ? '' : $what.', ').'the incident with its "version", and in "other_unresolved" up to '.self::OTHER_UNRESOLVED.' unresolved incidents that concern the same servers or projects.';
    }
}
