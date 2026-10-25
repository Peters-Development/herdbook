<?php

namespace App\Filament\Support;

use App\Enums\CheckState;
use App\Jobs\RunChecks;
use App\Models\Check;
use App\Models\Hostname;
use App\Services\Checks\DefaultChecks;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Actions on checks, shared by the checks list, the check page, the checks
 * of a hostname or server, and the dashboard.
 */
final class CheckActions
{
    public static function runNow(): Action
    {
        return Action::make('runNow')
            ->label(__('Check now'))
            ->icon(Heroicon::OutlinedPlay)
            ->color('gray')
            ->visible(fn (Check $record): bool => $record->enabled)
            // Network calls do not belong in the panel's action transaction,
            // and RunChecks makes its own (with the lock the scheduler uses).
            ->databaseTransaction(false)
            ->action(function (Check $record): void {
                $before = $record->last_checked_at;
                RunChecks::dispatchSync([$record->id], force: true);
                $record->refresh();

                if ($record->last_checked_at === null || $record->last_checked_at->equalTo($before ?? $record->last_checked_at->subSecond())) {
                    Notification::make()->warning()->title(__('Nothing recorded'))->body(__("Herdbook's own connection seemed to be down; try again in a moment."))->send();

                    return;
                }

                $notification = Notification::make()->title("{$record->name}: {$record->state->getLabel()}");

                match ($record->state) {
                    CheckState::Up => $notification->success()->body($record->last_response_ms !== null ? "{$record->last_response_ms} ms" : null),
                    default => $notification->danger()->body($record->last_error),
                };

                $notification->send();
            });
    }

    public static function pause(): Action
    {
        return Action::make('pause')
            ->label(__('Pause'))
            ->icon(Heroicon::OutlinedPause)
            ->color('gray')
            ->visible(fn (Check $record): bool => $record->enabled)
            ->action(fn (Check $record) => $record->update(['enabled' => false]));
    }

    public static function resume(): Action
    {
        return Action::make('resume')
            ->label(__('Resume'))
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->visible(fn (Check $record): bool => ! $record->enabled)
            ->action(fn (Check $record) => $record->update(['enabled' => true]));
    }

    public static function pauseBulk(): BulkAction
    {
        return BulkAction::make('pause')
            ->label(__('Pause'))
            ->icon(Heroicon::OutlinedPause)
            ->action(fn (Collection $records) => $records->each->update(['enabled' => false]))
            ->deselectRecordsAfterCompletion();
    }

    public static function resumeBulk(): BulkAction
    {
        return BulkAction::make('resume')
            ->label(__('Resume'))
            ->icon(Heroicon::OutlinedPlay)
            ->action(fn (Collection $records) => $records->each->update(['enabled' => true]))
            ->deselectRecordsAfterCompletion();
    }

    /**
     * The default checks for a hostname (DefaultChecks): the row's or page's
     * record, or $hostname where there is none (a relation manager's header).
     */
    public static function enableMonitoring(?Hostname $hostname = null): Action
    {
        return Action::make('enableMonitoring')
            ->label(__('Turn on monitoring'))
            ->icon(Heroicon::OutlinedSignal)
            ->color('success')
            ->visible(fn (?Hostname $record): bool => ($hostname ?? $record)?->canBeMonitored() ?? false)
            ->requiresConfirmation()
            ->modalDescription(function (?Hostname $record) use ($hostname): string {
                $subject = $hostname ?? $record;
                $redirect = $subject?->redirects_to;
                $local = (bool) $subject?->local_only;

                return implode(' ', array_filter([
                    match (true) {
                        $local && $redirect !== null => __('Creates a check for the redirect to :target that accepts any certificate: a name on the local network gets no certificate or DNS check.', ['target' => $redirect]),
                        $local => __('Creates a check for HTTPS that accepts any certificate: a name on the local network gets no certificate or DNS check.'),
                        $redirect !== null => __('Creates checks for the redirect to :target, the certificate and (without a proxy) DNS.', ['target' => $redirect]),
                        default => __('Creates checks for HTTPS, the certificate and (without a proxy) DNS.'),
                    },
                    __('Existing checks stay as they are.'),
                    $redirect === null ? null : __('The redirect gives a normal alert: the emergency alert belongs to the monitoring of :target itself.', ['target' => $redirect]),
                ]));
            })
            ->action(function (?Hostname $record) use ($hostname): void {
                $created = app(DefaultChecks::class)->createFor($hostname ?? $record);

                Notification::make()
                    ->success()
                    ->title($created === 0
                        ? __('All default checks already existed')
                        : trans_choice(':count check created|:count checks created', $created))
                    ->send();
            });
    }
}
