<?php

namespace App\Filament\Resources\Maintenances;

use App\Enums\NavigationGroup;
use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\Maintenances\Pages\CreateMaintenance;
use App\Filament\Resources\Maintenances\Pages\EditMaintenance;
use App\Filament\Resources\Maintenances\Pages\ListMaintenances;
use App\Filament\Resources\Maintenances\Pages\ViewMaintenance;
use App\Filament\Resources\Maintenances\Schemas\MaintenanceForm;
use App\Filament\Resources\Maintenances\Schemas\MaintenanceInfolist;
use App\Filament\Resources\Maintenances\Tables\MaintenancesTable;
use App\Filament\Support\MaintenanceSchema;
use App\Models\Maintenance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MaintenanceResource extends Resource
{
    protected static ?string $model = Maintenance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Knowledge;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getModelLabel(): string
    {
        return __('maintenance');
    }

    public static function getPluralModelLabel(): string
    {
        return __('maintenance');
    }

    public static function getNavigationBadge(): ?string
    {
        $overdue = Maintenance::query()->overdue()->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return __('Overdue');
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['servers', 'projects']);
    }

    /**
     * Automatic titles repeat ("Updates"): the date and where tell them apart.
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            __('When') => $record->starts_at->timezone(config('herdbook.display_timezone'))->translatedFormat(__('formats.date')),
            __('Where') => MaintenanceSchema::linkedTo($record),
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return MaintenanceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MaintenanceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaintenancesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenances::route('/'),
            'create' => CreateMaintenance::route('/create'),
            'view' => ViewMaintenance::route('/{record}'),
            'edit' => EditMaintenance::route('/{record}/edit'),
        ];
    }
}
