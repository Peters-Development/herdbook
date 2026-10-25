<?php

namespace App\Filament\Resources\Hostnames;

use App\Enums\NavigationGroup;
use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\RelationManagers\ChecksRelationManager;
use App\Filament\Resources\Hostnames\Pages\CreateHostname;
use App\Filament\Resources\Hostnames\Pages\EditHostname;
use App\Filament\Resources\Hostnames\Pages\ListHostnames;
use App\Filament\Resources\Hostnames\Pages\ViewHostname;
use App\Filament\Resources\Hostnames\Schemas\HostnameForm;
use App\Filament\Resources\Hostnames\Schemas\HostnameInfolist;
use App\Filament\Resources\Hostnames\Tables\HostnamesTable;
use App\Models\Hostname;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class HostnameResource extends Resource
{
    protected static ?string $model = Hostname::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Inventory;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'fqdn';

    public static function getModelLabel(): string
    {
        return __('hostname');
    }

    public static function getPluralModelLabel(): string
    {
        return __('hostnames');
    }

    public static function form(Schema $schema): Schema
    {
        return HostnameForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return HostnameInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HostnamesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChecksRelationManager::class,
            ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHostnames::route('/'),
            'create' => CreateHostname::route('/create'),
            'view' => ViewHostname::route('/{record}'),
            'edit' => EditHostname::route('/{record}/edit'),
        ];
    }
}
