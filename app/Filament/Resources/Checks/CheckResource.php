<?php

namespace App\Filament\Resources\Checks;

use App\Enums\CheckState;
use App\Enums\NavigationGroup;
use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\Checks\Pages\CreateCheck;
use App\Filament\Resources\Checks\Pages\EditCheck;
use App\Filament\Resources\Checks\Pages\ListChecks;
use App\Filament\Resources\Checks\Pages\ViewCheck;
use App\Filament\Resources\Checks\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Checks\Schemas\CheckForm;
use App\Filament\Resources\Checks\Schemas\CheckInfolist;
use App\Filament\Resources\Checks\Tables\ChecksTable;
use App\Models\Check;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CheckResource extends Resource
{
    protected static ?string $model = Check::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Inventory;

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('check');
    }

    public static function getPluralModelLabel(): string
    {
        return __('checks');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'target'];
    }

    /**
     * The menu shows how many checks are down.
     */
    public static function getNavigationBadge(): ?string
    {
        $down = Check::query()->where('enabled', true)->where('state', CheckState::Down)->count();

        return $down > 0 ? (string) $down : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return CheckForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CheckInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChecksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
            ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChecks::route('/'),
            'create' => CreateCheck::route('/create'),
            'view' => ViewCheck::route('/{record}'),
            'edit' => EditCheck::route('/{record}/edit'),
        ];
    }
}
