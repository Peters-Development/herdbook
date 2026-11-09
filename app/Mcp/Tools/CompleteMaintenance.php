<?php

namespace App\Mcp\Tools;

use App\Enums\MaintenanceStatus;
use App\Mcp\Tools\Concerns\ChangesMaintenance;
use App\Models\Maintenance;
use App\Rules\Moment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;
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
            .'A report given replaces the one there is (null clears it), so it needs the "version" you read: refused when someone changed it since. '
            .self::cutTextsSentence()
            .'Called again on maintenance that is done (by anyone) with only its id, or with what is stored, it returns it with "unchanged": true (it may have been done by someone else); anything else is refused: change done work with update_maintenance. Cancelled maintenance can\'t be completed: write the work down with log_maintenance instead. '
            .self::textsSentence('The report').self::answerSentence();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $this->idSchema($schema),
            'version' => $this->versionSchema($schema, 'needed with a report.'),
            'starts_at' => $schema->string()->description('When it started; not in the future. '.self::momentFormat()),
            'ends_at' => $schema->string()->nullable()->description('When it ended, in the same form; null for no end.'),
            'report' => $this->reportSchema($schema, partial: true),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'id' => $this->idRules(),
            'version' => $this->versionRules(),
            'starts_at' => ['sometimes', 'required', new Moment],
            'ends_at' => ['nullable', new Moment],
            'report' => $this->textRules(replacing: true),
        ], after: function (Validator $validator): void {
            // A null report clears the one there is: as a text given, only
            // over the one the agent read (required_with lets null through).
            $arguments = $validator->getData();

            if (array_key_exists('report', $arguments) && ! filled($arguments['version'] ?? null)) {
                $validator->errors()->add('version', __('A report replaces the one there is: give the "version" you read with get_maintenance.'));
            }
        });
        $maintenance = $this->findMaintenance((int) $input['id']);

        if (! $maintenance instanceof Maintenance) {
            return $maintenance;
        }

        $given = Arr::except($this->parseWork($input), ['id', 'version']);

        $completed = $maintenance->complete($given, function (array $attributes) use ($maintenance, $given): void {
            $this->checkTexts($maintenance, $given);
            $this->assertWork([
                'starts_at' => CarbonImmutable::make($attributes['starts_at']),
                'ends_at' => CarbonImmutable::make($attributes['ends_at']),
            ], MaintenanceStatus::Done);
        }, $input['version'] ?? null);

        return match (true) {
            $completed => $this->answer($maintenance),
            ! $maintenance->exists => $this->notFound($maintenance->getKey()),
            // Done already: called again (its answer lost), it gets the same answer.
            $maintenance->status === MaintenanceStatus::Done && $this->asStored($maintenance, $given) => $this->answer($maintenance, unchanged: true),
            $maintenance->status === MaintenanceStatus::Done => Response::error(__('This maintenance is done already. Change it with update_maintenance.')),
            $maintenance->status === MaintenanceStatus::Cancelled => Response::error(__('This maintenance was called off. Write the work down with log_maintenance, or ask a person to set it back to planned in Herdbook.')),
            default => $this->changedSince($maintenance),
        };
    }

    /**
     * Whether what a call gives is what is stored: nothing, or a repeat.
     *
     * @param  array<string, mixed>  $given
     */
    private function asStored(Maintenance $maintenance, array $given): bool
    {
        foreach ($given as $field => $value) {
            $stored = $maintenance->{$field};
            $same = $value instanceof CarbonInterface ? $stored instanceof CarbonInterface && $value->eq($stored) : $value === $stored;

            if (! $same) {
                return false;
            }
        }

        return true;
    }
}
