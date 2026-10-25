<?php

namespace App\Filament\Resources\Hostnames\Tables;

use App\Enums\CheckState;
use App\Models\Hostname;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HostnamesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['domain', 'environment.project', 'checks']))
            ->defaultSort('fqdn')
            ->columns([
                TextColumn::make('fqdn')->label(__('Hostname'))->fontFamily(FontFamily::Mono)->searchable()->sortable(),
                TextColumn::make('environment')
                    ->label(__('Points to'))
                    ->state(fn (Hostname $record): ?string => $record->environment?->full_name)
                    ->placeholder('—'),
                TextColumn::make('purpose')->label(__('Purpose'))->placeholder('—'),
                TextColumn::make('redirects_to')->label(__('Redirects to'))->fontFamily(FontFamily::Mono)->placeholder('—'),
                TextColumn::make('monitoring')->label(__('Monitoring'))->badge()->state(fn (Hostname $record): ?CheckState => $record->monitoringState())->placeholder(__('Off')),
                IconColumn::make('behind_proxy')->label(__('Behind a proxy'))->boolean(),
                IconColumn::make('local_only')->label(__('Local'))->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('domain_id')->label(__('Domain'))->relationship('domain', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
