<?php

namespace App\Filament\Resources\Clients\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Client'))->searchable()->sortable(),
                TextColumn::make('contact_name')->label(__('Contact'))->placeholder('—'),
                TextColumn::make('contact_phone')->label(__('Phone'))->placeholder('—'),
                TextColumn::make('projects_count')->label(__('Projects'))->counts('projects'),
                TextColumn::make('domains_count')->label(__('Domains'))->counts('domains'),
            ])
            ->filters([
                TernaryFilter::make('archived')
                    ->label(__('Clients'))
                    ->placeholder(__('Active clients'))
                    ->trueLabel(__('Archived clients'))
                    ->falseLabel(__('All clients'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query) => $query,
                        blank: fn (Builder $query) => $query->whereNull('archived_at'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
