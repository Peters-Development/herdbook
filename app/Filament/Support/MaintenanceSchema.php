<?php

namespace App\Filament\Support;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceTask;
use App\Models\Maintenance;
use App\Rules\NoSecretMaterial;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

/**
 * The fields, columns and actions that the maintenance pages, the "Maintenance"
 * tab on servers and projects, and the dashboard share.
 */
final class MaintenanceSchema
{
    /**
     * What was (or will be) done; the title can stay empty.
     *
     * @return list<mixed>
     */
    public static function workFields(): array
    {
        return [
            CheckboxList::make('tasks')
                ->label(__('Work'))
                ->options(MaintenanceTask::class)
                ->columns(5)
                ->required()
                ->columnSpanFull(),
            TextInput::make('title')
                ->label(__('Title'))
                ->placeholder(__('Default: the work done, such as "Updates and reboot"'))
                ->maxLength(255)
                ->columnSpanFull(),
        ];
    }

    /**
     * Between the moments a timestamp column holds (Maintenance::earliestMoment(),
     * latestMoment()): a year typed as "26" is 0026, which MySQL refuses.
     */
    public static function moment(string $name, string $label): DateTimePicker
    {
        // In the display timezone: Filament compares the limits with the
        // picker's state, which is in that timezone, not in UTC.
        return DateTimePicker::make($name)
            ->label($label)
            ->seconds(false)
            ->minDate(fn () => Maintenance::earliestMoment()->timezone(config('herdbook.display_timezone')))
            ->maxDate(fn () => self::latestShown())
            ->validationMessages(['after_or_equal' => self::outOfRange(), 'before_or_equal' => self::outOfRange()]);
    }

    /**
     * When work that is done began: not in the future, or it would count as
     * "last done" until that day comes.
     */
    public static function doneAt(string $label): DateTimePicker
    {
        return self::notInTheFuture(self::moment('starts_at', $label))->required();
    }

    /**
     * @param  Closure|null  $when  evaluated by the picker (it may take Get): limit only when true
     */
    public static function notInTheFuture(DateTimePicker $picker, ?Closure $when = null): DateTimePicker
    {
        $limited = fn (DateTimePicker $component): bool => $when === null || (bool) $component->evaluate($when);

        // In the display timezone, as in moment().
        return $picker
            ->maxDate(fn (DateTimePicker $component) => $limited($component) ? now(config('herdbook.display_timezone')) : self::latestShown())
            ->validationMessages([
                'after_or_equal' => self::outOfRange(),
                'before_or_equal' => fn (DateTimePicker $component): string => $limited($component)
                    ? __('Work that has been done cannot be in the future.')
                    : self::outOfRange(),
            ]);
    }

    public static function end(): DateTimePicker
    {
        // An end before 1970 lies before any start as well.
        return self::moment('ends_at', __('End'))
            ->afterOrEqual('starts_at')
            ->validationMessages([
                'after_or_equal' => __('The end cannot be before the start.'),
                'before_or_equal' => self::outOfRange(),
            ]);
    }

    public static function report(): MarkdownEditor
    {
        return MarkdownEditor::make('report')
            ->label(__('What was done'))
            ->rules([new NoSecretMaterial]);
    }

    /**
     * Who wrote it down, and through which agent. Named after user.name, so
     * Filament loads the user with the table.
     */
    public static function tasksColumn(): TextColumn
    {
        return TextColumn::make('tasks')
            ->label(__('Work'))
            ->state(fn (Maintenance $record): array => self::taskLabels($record))
            ->badge()
            ->color('gray');
    }

    public static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label(__('Status'))
            ->badge()
            ->state(fn (Maintenance $record): string => self::statusLabel($record))
            ->color(fn (Maintenance $record): string => self::statusColor($record));
    }

    public static function tasksEntry(): TextEntry
    {
        return TextEntry::make('tasks')
            ->label(__('Work'))
            ->state(fn (Maintenance $record): array => self::taskLabels($record))
            ->badge()
            ->color('gray');
    }

    public static function statusEntry(): TextEntry
    {
        return TextEntry::make('status')
            ->label(__('Status'))
            ->badge()
            ->state(fn (Maintenance $record): string => self::statusLabel($record))
            ->color(fn (Maintenance $record): string => self::statusColor($record));
    }

    /**
     * A planned maintenance becomes "Done", with what was done.
     */
    public static function completeAction(): Action
    {
        return Action::make('complete')
            ->label(__('Complete'))
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(self::whilePlanned())
            ->modalHeading(fn (Maintenance $record): string => __('Complete :title', ['title' => $record->title]))
            ->modalDescription(__('Sets the maintenance to "Done".'))
            ->modalSubmitActionLabel(__('Complete'))
            ->fillForm(fn (Maintenance $record, Action $action): array => [
                ...self::stillPlanned($record, $action)->completionDefaults(),
                'version' => $record->version(),
            ])
            ->schema([
                self::doneAt(__('Start')),
                self::end(),
                self::report(),
                // The report shown is the one as the modal opened: not saved over a newer one.
                Hidden::make('version'),
            ])
            ->action(function (Maintenance $record, array $data, Action $action): void {
                if ($record->complete($data, version: $data['version'] ?? '')) {
                    Notification::make()->success()->title(__('Maintenance completed'))->send();

                    return;
                }

                if ($record->exists && $record->status === MaintenanceStatus::Planned) {
                    // The modal stays open: what was typed can still be copied.
                    self::changedMeanwhile();
                    $action->halt();
                }

                self::noLongerPlanned();
            });
    }

    /**
     * Saving would undo a change someone else made since the form was opened.
     */
    public static function changedMeanwhile(): void
    {
        ChangedMeanwhile::notify(__('This maintenance was changed meanwhile.'));
    }

    /**
     * A planned maintenance that will not happen.
     */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('Call off'))
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->visible(self::whilePlanned())
            ->requiresConfirmation()
            ->modalHeading(fn (Maintenance $record): string => __('Call off :title', ['title' => $record->title]))
            ->modalDescription(__('The maintenance will not go ahead. If you do it after all, set the status back to "Planned".'))
            ->modalSubmitActionLabel(__('Call off'))
            ->mountUsing(fn (Maintenance $record, Action $action) => self::stillPlanned($record, $action))
            ->action(function (Maintenance $record): void {
                if (! $record->cancel()) {
                    self::noLongerPlanned();

                    return;
                }

                Notification::make()->success()->title(__('Maintenance called off'))->send();
            });
    }

    /**
     * "app-1, storage-1 · Shop": the servers, then the projects.
     */
    public static function linkedTo(Maintenance $record): ?string
    {
        return collect([$record->servers->pluck('name')->implode(', '), $record->projects->pluck('name')->implode(', ')])
            ->filter()
            ->implode(' · ') ?: null;
    }

    /**
     * "Updates · 21 Oct 2026": automatic titles repeat, the date tells them apart.
     */
    public static function titleWithDate(Maintenance $record): string
    {
        return "{$record->title} · {$record->starts_at->timezone(config('herdbook.display_timezone'))->translatedFormat(__('formats.date'))}";
    }

    public static function statusLabel(Maintenance $record): string
    {
        return $record->isOverdue() ? __('Overdue') : $record->status->getLabel();
    }

    public static function statusColor(Maintenance $record): string
    {
        return $record->isOverdue() ? 'warning' : $record->status->getColor();
    }

    /**
     * @return list<string>
     */
    private static function taskLabels(Maintenance $record): array
    {
        return array_map(
            fn (string $task): string => MaintenanceTask::tryFrom($task)?->getLabel() ?? $task,
            $record->tasks ?? [],
        );
    }

    /**
     * Only for planned maintenance. Filament asks again when the modal opens
     * and when it is submitted, of the record as it is then: while its own
     * modal is mounted the action stays, so stillPlanned() and complete() or
     * cancel() can say that someone else (an agent, another tab) was first,
     * instead of the click doing nothing.
     */
    private static function whilePlanned(): Closure
    {
        return fn (Maintenance $record, Action $action, HasActions $livewire): bool => $record->status === MaintenanceStatus::Planned
            || (method_exists($livewire, 'isActionMounted') && $livewire->isActionMounted($action));
    }

    /**
     * When the modal opens: a page loaded before someone else completed or
     * called it off still shows the button. Say so before anything is typed.
     */
    private static function stillPlanned(Maintenance $record, Action $action): Maintenance
    {
        if ($record->status !== MaintenanceStatus::Planned) {
            self::noLongerPlanned();
            $action->cancel();
        }

        return $record;
    }

    private static function noLongerPlanned(): void
    {
        Notification::make()->warning()->title(__('This maintenance is no longer planned.'))->send();
    }

    private static function outOfRange(): string
    {
        return __('Pick a moment between 1970 and 2038.');
    }

    /**
     * Maintenance::latestMoment() in the display timezone, as the pickers compare.
     */
    private static function latestShown(): CarbonImmutable
    {
        return Maintenance::latestMoment()->timezone(config('herdbook.display_timezone'));
    }
}
