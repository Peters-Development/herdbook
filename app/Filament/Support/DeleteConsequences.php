<?php

namespace App\Filament\Support;

use App\Models\Check;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Hostname;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

/**
 * Delete actions for the inventory that say what goes with it. Bulk delete
 * is not offered for these: one at a time, with the consequences in view.
 */
final class DeleteConsequences
{
    public static function project(): DeleteAction
    {
        return DeleteAction::make()->modalDescription(fn (Project $record): string => self::sentence(
            [':count environment|:count environments' => $record->environments()->count()],
            self::pausedChecks($record->hostnames()->withCount(['checks' => fn ($query) => $query->where('enabled', true)])->get()->sum('checks_count'))
                .__('Linked incidents, maintenance, documents, credentials and subscriptions are kept.'),
        ));
    }

    public static function environment(): DeleteAction
    {
        return DeleteAction::make()->modalDescription(fn (Environment $record): string => trim(
            self::pausedChecks(Check::query()->where('enabled', true)->whereIn('hostname_id', $record->hostnames()->select('id'))->count())
                .__('The servers are kept.'),
        ));
    }

    /**
     * A server that is still in use cannot go: set it to "Decommissioned", or
     * take it out of its environments and dependencies first.
     */
    public static function server(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(fn (Server $record): string => self::inUse($record)
                ? __('This server is still in use and cannot be deleted. Set it to "Decommissioned", or first take it out of its environments and dependencies.')
                : self::sentence([':count check with its history|:count checks with their history' => $record->checks()->count()], __('Linked incidents, maintenance, documents and credentials are kept.')))
            ->before(function (DeleteAction $action, Server $record): void {
                if (self::inUse($record)) {
                    Notification::make()
                        ->danger()
                        ->title(__('This server is still in use'))
                        ->body(__('Set it to "Decommissioned", or first take it out of its environments and dependencies.'))
                        ->send();

                    $action->cancel();
                }
            });
    }

    public static function domain(): DeleteAction
    {
        return DeleteAction::make()->modalDescription(fn (Domain $record): string => self::sentence([
            ':count hostname|:count hostnames' => $record->hostnames()->count(),
            ':count check on those hostnames|:count checks on those hostnames' => $record->hostnames()->withCount('checks')->get()->sum('checks_count'),
        ], __('Linked incidents, documents and credentials are kept.')));
    }

    public static function hostname(): DeleteAction
    {
        return DeleteAction::make()->modalDescription(fn (Hostname $record): string => self::sentence([
            ':count check with its history|:count checks with their history' => $record->checks()->count(),
        ]));
    }

    /**
     * Its servers stay, without a network.
     */
    public static function network(): DeleteAction
    {
        return DeleteAction::make()->modalDescription(function (Network $record): string {
            $servers = $record->servers()->count();

            return self::sentence(
                [':count address|:count addresses' => $record->addresses()->count()],
                ($servers > 0 ? trans_choice('The server in this network is kept, without a network.|The :count servers in this network are kept, without a network.', $servers).' ' : '')
                    .__('Linked incidents, documents and credentials are kept.'),
            );
        });
    }

    private static function inUse(Server $server): bool
    {
        return $server->assignments()->exists() || $server->dependents()->exists();
    }

    private static function pausedChecks(int $count): string
    {
        return $count > 0
            ? trans_choice('The check on the hostnames is paused (the hostnames stay, without an environment).|The :count checks on the hostnames are paused (the hostnames stay, without an environment).', $count).' '
            : '';
    }

    /**
     * @param  array<string, int>  $counts  ":count thing|:count things" (a translation key) => count
     */
    private static function sentence(array $counts, string $rest = ''): string
    {
        $parts = collect($counts)->filter()->map(fn (int $count, string $what): string => trans_choice($what, $count));

        $sentence = $parts->isEmpty() ? __('Nothing else is deleted with it.') : __('This also deletes: :items.', ['items' => $parts->implode(', ')]);

        return trim("{$sentence} {$rest}");
    }
}
