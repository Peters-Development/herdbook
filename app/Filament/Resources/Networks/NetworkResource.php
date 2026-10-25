<?php

namespace App\Filament\Resources\Networks;

use App\Enums\NavigationGroup;
use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\RelationManagers\CredentialReferencesRelationManager;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\RelationManagers\IncidentsRelationManager;
use App\Filament\Resources\Networks\Pages\CreateNetwork;
use App\Filament\Resources\Networks\Pages\EditNetwork;
use App\Filament\Resources\Networks\Pages\ListNetworks;
use App\Filament\Resources\Networks\Pages\ViewNetwork;
use App\Filament\Resources\Networks\Schemas\NetworkForm;
use App\Filament\Resources\Networks\Schemas\NetworkInfolist;
use App\Filament\Resources\Networks\Tables\NetworksTable;
use App\Models\Network;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class NetworkResource extends Resource
{
    protected static ?string $model = Network::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWifi;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Inventory;

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('network');
    }

    public static function getPluralModelLabel(): string
    {
        return __('networks');
    }

    /**
     * The search bar finds the network a device is in by its address, name
     * or MAC address (a server is found by its own resource).
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'subnet', 'gateway', 'public_ip', 'addresses.address', 'addresses.name', 'addresses.mac_address'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            __('Subnet') => $record->subnet,
            __('Location') => $record->location,
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return NetworkForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return NetworkInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NetworksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            IncidentsRelationManager::class,
            DocumentsRelationManager::class,
            CredentialReferencesRelationManager::class,
            ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNetworks::route('/'),
            'create' => CreateNetwork::route('/create'),
            'view' => ViewNetwork::route('/{record}'),
            'edit' => EditNetwork::route('/{record}/edit'),
        ];
    }
}
