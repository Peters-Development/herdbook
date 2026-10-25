<?php

namespace App\Filament\Resources\CredentialReferences;

use App\Enums\NavigationGroup;
use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\CredentialReferences\Pages\CreateCredentialReference;
use App\Filament\Resources\CredentialReferences\Pages\EditCredentialReference;
use App\Filament\Resources\CredentialReferences\Pages\ListCredentialReferences;
use App\Filament\Resources\CredentialReferences\Pages\ViewCredentialReference;
use App\Filament\Resources\CredentialReferences\Schemas\CredentialReferenceForm;
use App\Filament\Resources\CredentialReferences\Schemas\CredentialReferenceInfolist;
use App\Filament\Resources\CredentialReferences\Tables\CredentialReferencesTable;
use App\Models\CredentialReference;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CredentialReferenceResource extends Resource
{
    protected static ?string $model = CredentialReference::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Knowledge;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'label';

    public static function getModelLabel(): string
    {
        return __('credential');
    }

    public static function getPluralModelLabel(): string
    {
        return __('credentials');
    }

    public static function getNavigationLabel(): string
    {
        return __('Credentials');
    }

    public static function form(Schema $schema): Schema
    {
        return CredentialReferenceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CredentialReferenceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CredentialReferencesTable::configure($table);
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
            'index' => ListCredentialReferences::route('/'),
            'create' => CreateCredentialReference::route('/create'),
            'view' => ViewCredentialReference::route('/{record}'),
            'edit' => EditCredentialReference::route('/{record}/edit'),
        ];
    }
}
