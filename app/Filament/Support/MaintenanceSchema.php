<?php

namespace App\Filament\Support;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceTask;
use App\Models\Maintenance;
use App\Rules\NoSecretMaterial;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
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

    public static function moment(string $name, string $label): DateTimePicker
    {
        return DateTimePicker::make($name)
            ->label($label)
            ->native(false)
            ->displayFormat(__('formats.datetime'))
            ->seconds(false);
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
        // In the display timezone: Filament compares the limit with the
        // picker's state, which is in that timezone, not in UTC.
        return $picker
            ->maxDate(fn (DateTimePicker $component) => ($when === null || $component->evaluate($when)) ? now(config('herdbook.display_timezone')) : null)
            ->validationMessages(['before_or_equal' => __('Work that has been done cannot be in the future.')]);
    }

    public static function end(): DateTimePicker
    {
        return self::moment('ends_at', __('End'))
            ->afterOrEqual('starts_at')
            ->validationMessages(['after_or_equal' => __('The end cannot be before the start.')]);
    }

    public static function report(): MarkdownEditor
    {
        return MarkdownEditor::make('report')
            ->label(__('What was done'))
            ->rules([new NoSecretMaterial]);
    }

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
            ->visible(fn (Maintenance $record): bool => $record->status === MaintenanceStatus::Planned)
            ->modalHeading(fn (Maintenance $record): string => __('Complete :title', ['title' => $record->title]))
            ->modalDescription(__('Sets the maintenance to "Done".'))
            ->modalSubmitActionLabel(__('Complete'))
            ->fillForm(fn (Maintenance $record): array => [
                // Done before its planned time: it started now, and a planned end is not an end.
                'starts_at' => $record->starts_at->isFuture() ? now() : $record->starts_at,
                'ends_at' => $record->ends_at?->isPast() ? $record->ends_at : null,
                'report' => $record->report,
            ])
            ->schema([
                self::doneAt(__('Start')),
                self::end(),
                self::report(),
            ])
            ->action(function (Maintenance $record, array $data): void {
                $record->update([...$data, 'status' => MaintenanceStatus::Done]);

                Notification::make()->success()->title(__('Maintenance completed'))->send();
            });
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
            ->visible(fn (Maintenance $record): bool => $record->status === MaintenanceStatus::Planned)
            ->requiresConfirmation()
            ->modalHeading(fn (Maintenance $record): string => __('Call off :title', ['title' => $record->title]))
            ->modalDescription(__('The maintenance will not go ahead. If you do it after all, set the status back to "Planned".'))
            ->modalSubmitActionLabel(__('Call off'))
            ->action(function (Maintenance $record): void {
                $record->update(['status' => MaintenanceStatus::Cancelled]);

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
}
