<?php

namespace App\Filament\Resources\Domains\RelationManagers;

use App\Enums\CheckState;
use App\Filament\Resources\Hostnames\HostnameResource;
use App\Filament\Resources\Hostnames\Schemas\HostnameForm;
use App\Filament\Support\CheckActions;
use App\Filament\Support\DeleteConsequences;
use App\Models\Hostname;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HostnamesRelationManager extends RelationManager
{
    protected static string $relationship = 'hostnames';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Hostnames');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components(HostnameForm::components($this->getOwnerRecord()));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('fqdn')
            ->modelLabel(__('hostname'))
            ->pluralModelLabel(__('hostnames'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['environment.project', 'checks']))
            ->defaultSort('fqdn')
            ->columns([
                TextColumn::make('fqdn')->label(__('Hostname'))->fontFamily(FontFamily::Mono)->searchable(),
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
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordUrl(fn (Hostname $record): string => HostnameResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                CheckActions::enableMonitoring(),
                EditAction::make(),
                DeleteConsequences::hostname(),
            ]);
    }
}
