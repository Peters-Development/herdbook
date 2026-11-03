<?php

namespace App\Filament\Resources\Domains\Tables;

use App\Enums\CostPeriod;
use App\Filament\Resources\Domains\Schemas\DomainForm;
use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\Domain;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'registrar', 'mailServer']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Domain'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Domain $record): string => $record->client->name ?? __('Own domain')),
                TextColumn::make('registrar.name')->label(__('Registrar'))->placeholder('—'),
                TextColumn::make('expires_on')->label(__('Expires'))->date(__('formats.date'))->sortable()->placeholder('—'),
                // Without a contract, a domain is a local one (Domain::hasContract()).
                RenewalSchema::statusColumn()->placeholder(__('Local')),
                CostSchema::column()->toggleable(),
                IconColumn::make('auto_renews')
                    ->label(__('Automatic'))
                    ->state(fn (Domain $record): ?bool => $record->hasContract() ? $record->auto_renews : null)
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('mailServer.name')->label(__('Mail server'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hostnames_count')->label(__('Hostnames'))->counts('hostnames')->toggleable(),
                IconColumn::make('local_only')->label(__('Local'))->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                RenewalSchema::attentionFilter(Domain::class),
                RenewalSchema::statusFilter(Domain::class),
                CostSchema::missingFilter(),
                SelectFilter::make('registrar_id')->label(__('Registrar'))->relationship('registrar', 'name')->preload(),
                SelectFilter::make('mail_server_id')->label(__('Mail server'))->relationship('mailServer', 'name', fn (Builder $query) => $query->has('mailDomains'))->preload(),
                TernaryFilter::make('client')
                    ->label(__('Belongs to'))
                    ->placeholder(__('All domains'))
                    ->trueLabel(__('Client domains'))
                    ->falseLabel(__('Own domains'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('client_id'),
                        false: fn (Builder $query) => $query->whereNull('client_id'),
                    ),
            ])
            ->recordActions([
                RenewalSchema::renewAction(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    RenewalSchema::renewBulkAction(),
                    CostSchema::bulkAction(CostPeriod::Year),
                    BulkAction::make('setMailServer')
                        ->label(__('Set mail server'))
                        ->icon(Heroicon::OutlinedEnvelope)
                        ->modalHeading(__('Set mail server'))
                        ->modalDescription(__('For every domain you selected. Leave it empty and their mail counts through the servers of their projects again.'))
                        ->modalSubmitActionLabel(__('Set'))
                        ->schema([DomainForm::mailServerField()])
                        ->action(function (Collection $records, array $data): void {
                            // Through Eloquent, so each change is logged on its domain.
                            $records->each->update(['mail_server_id' => $data['mail_server_id'] ?? null]);

                            Notification::make()->success()->title(trans_choice('Mail server set for :count domain.|Mail server set for :count domains.', $records->count()))->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
