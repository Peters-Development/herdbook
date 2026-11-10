<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Rules\Moment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;

/**
 * The tools that change an incident that is there: on its locked row
 * (GuardsChanges), refused when it changed since the agent read it (its
 * version), as monitoring changes it too.
 */
trait ChangesIncident
{
    use WritesIncidents;

    /**
     * Arguments => attributes of its texts: markdown, and shown as text.
     */
    private const array MARKDOWN = ['root_cause' => 'root_cause', 'report' => 'body'];

    private const array PLAIN = ['impact' => 'impact'];

    protected function versionSchema(JsonSchema $schema, string $when): Type
    {
        return $this->versionSchemaFrom($schema, $when, 'get_incident, list_incidents');
    }

    protected function changedSince(Incident $incident): Response
    {
        return Response::error(__('This incident changed since you read it (now: :status). Read it again with get_incident.', ['status' => $incident->status->value]));
    }

    /**
     * The given arguments as they will be stored: a title tidied, moments
     * in minutes, the report as body.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function parseChanges(array $input): array
    {
        $changes = Arr::except($input, ['id', 'version']);

        foreach (['started_at', 'detected_at', 'resolved_at'] as $field) {
            if (array_key_exists($field, $changes)) {
                $changes[$field] = self::moment($changes[$field]);
            }
        }

        if (array_key_exists('title', $changes)) {
            $changes['title'] = Str::squish($changes['title']);
        }

        if (array_key_exists('report', $changes)) {
            $changes['body'] = Arr::pull($changes, 'report');
        }

        return $changes;
    }

    /**
     * When it was resolved follows its status, unless given: reopened, it
     * has none; resolved, now if it had none.
     *
     * @param  array<string, mixed>  $changes  parsed
     * @return array<string, mixed>
     */
    protected static function withResolution(Incident $incident, array $changes): array
    {
        if (! array_key_exists('status', $changes) || array_key_exists('resolved_at', $changes)) {
            return $changes;
        }

        return match (true) {
            $changes['status'] !== IncidentStatus::Resolved->value => [...$changes, 'resolved_at' => null],
            $incident->resolved_at === null => [...$changes, 'resolved_at' => CarbonImmutable::now()->startOfMinute()],
            default => $changes,
        };
    }

    /**
     * On the locked row: its texts (not over one the agent couldn't read
     * whole, no new links), and its moments as they will be, the changes
     * over what is stored.
     *
     * @param  array<string, mixed>  $changes  parsed
     */
    protected function checkChanges(Incident $incident, array $changes): void
    {
        $this->checkTexts($incident, [...$changes, ...(array_key_exists('body', $changes) ? ['report' => $changes['body']] : [])], self::MARKDOWN, self::PLAIN);

        $moments = [];

        foreach (['started_at', 'detected_at', 'resolved_at'] as $field) {
            $moments[$field] = array_key_exists($field, $changes) ? $changes[$field] : $incident->{$field}?->toImmutable();
        }

        $validator = Validator::make([], []);
        $this->checkMoments($validator, array_intersect_key($moments, $changes), $moments['started_at'], ['detected_at', 'resolved_at']);

        // Moments it keeps can't come before a start that moves.
        if (array_key_exists('started_at', $changes) && $moments['started_at'] !== null) {
            foreach (['detected_at', 'resolved_at'] as $field) {
                if (! array_key_exists($field, $changes) && $moments[$field] !== null && $moments[$field]->lt($moments['started_at'])) {
                    $validator->errors()->add('started_at', __('It cannot be after when it was detected or resolved.'));

                    break;
                }
            }
        }

        // Only a resolved incident has a resolution time.
        $status = array_key_exists('status', $changes) ? IncidentStatus::from($changes['status']) : $incident->status;

        if (($changes['resolved_at'] ?? null) !== null && $status !== IncidentStatus::Resolved && ! $validator->errors()->has('resolved_at')) {
            $validator->errors()->add('resolved_at', __('Only a resolved incident has a resolution time: give status resolved too, or use resolve_incident.'));
        }

        // Its links as they will be, when they change: one server or project at least.
        if (array_key_exists('server_ids', $changes) || array_key_exists('project_ids', $changes)) {
            $servers = $changes['server_ids'] ?? $incident->servers->modelKeys();
            $projects = $changes['project_ids'] ?? $incident->projects->modelKeys();

            if ($servers === [] && $projects === []) {
                $validator->errors()->add('server_ids', __('Link it to at least one server or project: server_ids or project_ids, from search_inventory.'));
            }
        }

        if ($validator->errors()->isNotEmpty()) {
            throw self::named(new ValidationException($validator));
        }
    }

    /**
     * The rule of a moment an agent may clear (null).
     *
     * @return list<mixed>
     */
    protected static function momentRules(): array
    {
        return ['nullable', new Moment];
    }
}
