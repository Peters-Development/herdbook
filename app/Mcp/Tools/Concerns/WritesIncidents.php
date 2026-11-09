<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Serializer;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Rules\Moment;
use Carbon\CarbonImmutable;
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

            if ($moment->lt(Maintenance::earliestMoment()) || $moment->gt(Maintenance::latestMoment())) {
                $validator->errors()->add($field, __('Pick a moment between 1970 and 2038.'));
            } elseif ($moment->isFuture()) {
                $validator->errors()->add($field, __('It cannot lie in the future.'));
            } elseif ($start !== null && in_array($field, $after, true) && $moment->lt($start)) {
                $validator->errors()->add($field, __('It cannot be before the start.'));
            }
        }
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
