<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Enums\EnvironmentKind;
use App\Enums\ServerRole;
use App\Filament\Support\DeleteConsequences;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Rules\DistinctItems;
use App\Rules\NoSecretMaterial;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

class EnvironmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'environments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Environments');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255)
                    ->default(__('Production'))
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('project_id', $this->getOwnerRecord()->getKey())),
                Select::make('kind')
                    ->label(__('Type'))
                    ->options(EnvironmentKind::class)
                    ->default(EnvironmentKind::Production)
                    ->required(),
                TextInput::make('deploy_method')->label(__('Deployed via'))->placeholder('deploy.sh, GitHub Actions, …')->maxLength(255),
                TextInput::make('branch')->label(__('Branch'))->maxLength(255),
                TextInput::make('path')->label(__('Path on the server'))->maxLength(255),
                TextInput::make('sort_order')->label(__('Order'))->integer()->minValue(0)->maxValue(65535)->default(0),
                Repeater::make('serverAssignments')
                    ->label(__('Runs on'))
                    ->relationship()
                    ->schema([
                        Select::make('server_id')
                            ->label(__('Server'))
                            ->relationship('server', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('role')
                            ->label(__('Role'))
                            ->options(ServerRole::class)
                            ->default(ServerRole::App)
                            ->required(),
                        TextInput::make('notes')->label(__('Note'))->maxLength(255),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->addActionLabel(__('Add server'))
                    ->rules([new DistinctItems(['server_id', 'role'], __('The same server is in the list twice with the same role.'))])
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label(__('Notes'))
                    ->rows(3)->maxLength(10000)
                    ->rules([new NoSecretMaterial])
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modelLabel(__('environment'))
            ->pluralModelLabel(__('environments'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with('serverAssignments.server'))
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->label(__('Environment')),
                TextColumn::make('kind')->label(__('Type'))->badge(),
                TextColumn::make('servers')
                    ->label(__('Runs on'))
                    ->state(fn (Environment $record): array => $record->serverAssignments
                        ->map(fn (EnvironmentServer $assignment): string => "{$assignment->server->name} ({$assignment->role->getLabel()})")
                        ->all())
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('deploy_method')->label(__('Deployed via'))->placeholder('—')->toggleable(),
                TextColumn::make('branch')->label(__('Branch'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteConsequences::environment(),
            ]);
    }
}
