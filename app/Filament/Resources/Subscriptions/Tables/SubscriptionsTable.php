<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\Subscription;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['provider', 'project']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Subscription'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Subscription $record): ?string => $record->provider?->name),
                TextColumn::make('project.name')->label(__('Project'))->placeholder('—')->toggleable(),
                CostSchema::column(),
                TextColumn::make('expires_on')->label(__('Expires'))->date(__('formats.date'))->sortable()->placeholder('—'),
                RenewalSchema::statusColumn(),
                IconColumn::make('auto_renews')->label(__('Automatic'))->boolean()->toggleable(),
            ])
            ->filters([
                RenewalSchema::attentionFilter(Subscription::class),
                RenewalSchema::statusFilter(Subscription::class),
                SelectFilter::make('provider_id')->label(__('Provider'))->relationship('provider', 'name')->preload(),
                SelectFilter::make('project_id')->label(__('Project'))->relationship('project', 'name')->preload(),
                CostSchema::missingFilter(),
            ])
            ->recordActions([
                RenewalSchema::renewAction(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    RenewalSchema::renewBulkAction(),
                ]),
            ]);
    }
}
