<?php

namespace App\Filament\RelationManagers;

use App\Filament\Resources\Checks\CheckResource;
use App\Filament\Resources\Checks\Tables\ChecksTable;
use App\Filament\Support\CheckActions;
use App\Models\Check;
use App\Models\Hostname;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The checks of a hostname or a server.
 */
class ChecksRelationManager extends RelationManager
{
    protected static string $relationship = 'checks';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Monitoring');
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();

        return $table
            ->modelLabel(__('check'))
            ->pluralModelLabel(__('checks'))
            ->recordTitleAttribute('name')
            ->poll('30s')
            ->defaultSort(fn (Builder $query): Builder => ChecksTable::worstFirst($query))
            ->columns(ChecksTable::columns())
            ->emptyStateHeading(__('No checks yet'))
            ->emptyStateDescription(match (true) {
                $owner instanceof Hostname && $owner->canBeMonitored() && $owner->local_only => __('Turn on monitoring for HTTPS.'),
                $owner instanceof Hostname && $owner->canBeMonitored() => __('Turn on monitoring for HTTPS, the certificate and DNS.'),
                $owner instanceof Hostname => __('A wildcard is not a name that answers: monitor the hostnames under it.'),
                default => null,
            })
            ->headerActions([
                CheckActions::enableMonitoring($owner instanceof Hostname ? $owner : null)
                    ->visible($owner instanceof Hostname && $owner->canBeMonitored()),
                Action::make('create')
                    ->label(__('New check'))
                    ->url(fn (): string => CheckResource::getUrl('create', [
                        ($owner instanceof Hostname ? 'hostname' : 'server') => $owner->getKey(),
                    ])),
            ])
            ->recordUrl(fn (Check $record): string => CheckResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                CheckActions::runNow(),
                CheckActions::pause(),
                CheckActions::resume(),
            ]);
    }
}
