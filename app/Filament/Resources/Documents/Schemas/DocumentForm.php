<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Enums\DocumentKind;
use App\Filament\Support\LinkSchema;
use App\Rules\NoSecretMaterial;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextInput::make('title')->label(__('Title'))->required()->maxLength(255)->columnSpan(2),
                        Select::make('kind')->label(__('Type'))->options(DocumentKind::class)->default(DocumentKind::Runbook)->required(),
                        MarkdownEditor::make('body')
                            ->label(__('Content'))
                            ->required()
                            ->rules([new NoSecretMaterial])
                            ->columnSpanFull(),
                    ]),
                LinkSchema::formSection(),
            ]);
    }
}
