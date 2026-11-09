<?php

namespace App\Mcp\Tools;

use App\Enums\MaintenanceStatus;
use App\Mcp\Tools\Concerns\ChangesMaintenance;
use App\Models\Maintenance;
use App\Rules\Moment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

/**
 * Planned work done, as "Complete" in the panel: Maintenance::complete(),
 * with the same defaults.
 */
#[Name('complete_maintenance')]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld(false)]
class CompleteMaintenance extends Tool
{
    use ChangesMaintenance;

    public function description(): string
    {
        return 'Complete planned maintenance: it becomes done, with when it was done and what was done. '
            .'Left out, starts_at is the planned start, or now if that is still to come; ends_at is the planned end if that has passed, else none; the report stays. '
            .'A report given replaces the one there is, so it needs the "version" you read: refused when someone changed it since. '
            .self::cutTextsSentence()
            .'On maintenance that is done already, by anyone, only the id (and version) returns it as it is now; to change done work use update_maintenance. Cancelled maintenance can\'t be completed: write the work down with log_maintenance instead. '
            .self::textsSentence('The report').self::answerSentence();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $this->idSchema($schema),
            'version' => $this->versionSchema($schema, 'Its "version" from get_maintenance or list_maintenance; needed with a report. Refused when it changed since.'),
            'starts_at' => $schema->string()->description('When it started; not in the future. '.self::momentFormat()),
            'ends_at' => $schema->string()->nullable()->description('When it ended, in the same form; null for no end.'),
            'report' => $this->reportSchema($schema, partial: true),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'id' => $this->idRules(),
            'version' => ['required_with:report', 'nullable', 'string', 'max:64'],
            'starts_at' => ['sometimes', 'required', new Moment],
            'ends_at' => ['nullable', new Moment],
            'report' => $this->textRules(),
        ]);
        $maintenance = $this->findMaintenance((int) $input['id']);

        if (! $maintenance instanceof Maintenance) {
            return $maintenance;
        }

        $given = Arr::except($this->parseWork($input), ['id', 'version']);

        $completed = $maintenance->complete($given, function (array $attributes) use ($maintenance, $given): void {
            $this->refuseCutTexts($maintenance, $given);
            $this->assertWork([
                'starts_at' => CarbonImmutable::make($attributes['starts_at']),
                'ends_at' => CarbonImmutable::make($attributes['ends_at']),
            ], MaintenanceStatus::Done);
        }, $input['version'] ?? null);

        return match (true) {
            $completed => $this->answer($maintenance),
            ! $maintenance->exists => $this->notFound($maintenance->getKey()),
            // Done already: called again with only the id, it gets the same answer.
            $maintenance->status === MaintenanceStatus::Done && $given === [] => $this->answer($maintenance),
            $maintenance->status === MaintenanceStatus::Done => Response::error(__('This maintenance is done already. Change it with update_maintenance.')),
            $maintenance->status === MaintenanceStatus::Cancelled => Response::error(__('This maintenance was cancelled. Write the work down with log_maintenance, or ask a person to set it back to planned in Herdbook.')),
            default => $this->changedSince($maintenance),
        };
    }
}
