<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\HealthState;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Rules\NoSecretMaterial;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Project'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, ?string $state, string $operation): void {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')
                            ->label(__('Slug'))
                            ->required()
                            ->maxLength(255)
                            ->alphaDash()
                            ->unique(ignoreRecord: true),
                        Select::make('type')
                            ->label(__('Type'))
                            ->options(ProjectType::class)
                            ->default(ProjectType::Product)
                            ->required(),
                        Select::make('status')
                            ->label(__('Status'))
                            ->options(ProjectStatus::class)
                            ->default(ProjectStatus::Active)
                            ->required(),
                        Select::make('client_id')
                            ->label(__('Client'))
                            ->relationship('client', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder(__('Own project'))
                            ->createOptionForm([
                                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                            ]),
                        TextInput::make('repository_url')
                            ->label(__('Repository'))
                            ->url()
                            ->maxLength(255),
                        Toggle::make('on_status_screen')
                            ->label(__('On the status screen'))
                            ->helperText(__('The name and the state of production appear on the status screen. Anyone who can see the screen can see them.'))
                            ->columnSpanFull(),
                        Toggle::make('unmonitored')
                            ->label(__('Does not need monitoring'))
                            ->helperText(__('For something that needs no checks, such as a server on your own network. Without checks, the project then shows ":unmonitored" instead of ":unwatched", and does not count as a gap. If checks are added after all, they count as usual.', [
                                'unmonitored' => HealthState::Unmonitored->getLabel(),
                                'unwatched' => HealthState::Unwatched->getLabel(),
                            ]))
                            ->columnSpanFull(),
                        TagsInput::make('tags')
                            ->label(__('Stack'))
                            ->placeholder('Laravel, Filament, …')
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label(__('Description'))
                            ->rows(3)->maxLength(10000)
                            ->rules([new NoSecretMaterial])
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Notes'))
                    ->collapsible()
                    ->schema([
                        MarkdownEditor::make('notes')
                            ->label(__('Notes'))
                            ->hiddenLabel()
                            ->rules([new NoSecretMaterial]),
                    ]),
            ]);
    }
}
