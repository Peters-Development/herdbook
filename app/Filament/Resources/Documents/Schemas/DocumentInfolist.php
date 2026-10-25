<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Filament\Support\LinkSchema;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('kind')->label(__('Type'))->badge(),
                        TextEntry::make('updated_at')->label(__('Updated'))->dateTime(__('formats.datetime')),
                    ]),
                Section::make(__('Content'))
                    ->schema([
                        TextEntry::make('body')->label(__('Content'))->hiddenLabel()->markdown()->prose(),
                    ]),
                LinkSchema::infolistSection(),
            ]);
    }
}
