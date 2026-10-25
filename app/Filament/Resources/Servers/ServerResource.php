<?php

namespace App\Filament\Resources\Servers;

use App\Enums\NavigationGroup;
use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\RelationManagers\ChecksRelationManager;
use App\Filament\RelationManagers\CredentialReferencesRelationManager;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\RelationManagers\IncidentsRelationManager;
use App\Filament\RelationManagers\MaintenancesRelationManager;
use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\Schemas\ServerForm;
use App\Filament\Resources\Servers\Schemas\ServerInfolist;
use App\Filament\Resources\Servers\Tables\ServersTable;
use App\Models\Server;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ServerResource extends Resource
{
    protected static ?string $model = Server::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Inventory;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('server');
    }

    public static function getPluralModelLabel(): string
    {
        return __('servers');
    }

    /**
     * "What runs on 203.0.113.10?" is answered from the search bar.
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'provider_hostname', 'public_ipv4', 'public_ipv6', 'private_ip', 'tailscale_ip'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['provider', 'network']);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'IP' => $record->public_ipv4 ?? $record->private_ip,
            __('Provider') => $record->provider?->name,
            __('Network') => $record->network?->name,
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return ServerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ServerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChecksRelationManager::class,
            IncidentsRelationManager::class,
            MaintenancesRelationManager::class,
            DocumentsRelationManager::class,
            CredentialReferencesRelationManager::class,
            ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServers::route('/'),
            'create' => CreateServer::route('/create'),
            'view' => ViewServer::route('/{record}'),
            'edit' => EditServer::route('/{record}/edit'),
        ];
    }
}
