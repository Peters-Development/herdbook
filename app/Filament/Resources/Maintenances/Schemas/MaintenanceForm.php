<?php

namespace App\Filament\Resources\Maintenances\Schemas;

use App\Enums\MaintenanceStatus;
use App\Filament\Support\LinkSchema;
use App\Filament\Support\MaintenanceSchema;
use App\Rules\NoSecretMaterial;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MaintenanceForm
{
    /**
     * "?status=planned" on the create page ("Schedule" on a server or project).
     */
    public const string STATUS_PARAMETER = 'status';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Maintenance'))
                    ->columns(3)
                    ->schema([
                        ...MaintenanceSchema::workFields(),
                        Select::make('status')
                            ->label(__('Status'))
                            ->options(MaintenanceStatus::class)
                            ->default(fn (): MaintenanceStatus => self::requestedStatus() ?? MaintenanceStatus::Done)
                            ->required()
                            ->live(),
                        // Planned work has no time yet; work that is done is not in the future.
                        MaintenanceSchema::notInTheFuture(
                            MaintenanceSchema::moment('starts_at', __('Start')),
                            fn (Get $get): bool => self::statusFrom($get('status')) === MaintenanceStatus::Done,
                        )
                            ->default(fn () => self::requestedStatus() === MaintenanceStatus::Planned ? null : now())
                            ->required(),
                        MaintenanceSchema::end()
                            ->helperText(__('For a maintenance window; optional.')),
                    ]),
                Section::make(__('Work plan'))
                    ->description(__('What is going to happen: the steps, what goes down and how to roll back.'))
                    ->collapsible()
                    ->schema([
                        MarkdownEditor::make('plan')->label(__('Work plan'))->hiddenLabel()->rules([new NoSecretMaterial]),
                    ]),
                Section::make(__('What was done'))
                    ->description(__('What stood out and what is still to do.'))
                    ->collapsible()
                    ->schema([
                        MaintenanceSchema::report()->hiddenLabel(),
                    ]),
                LinkSchema::formSection(['projects', 'servers']),
            ]);
    }

    /**
     * The status named in ?status=, if it is one.
     */
    public static function requestedStatus(): ?MaintenanceStatus
    {
        return self::statusFrom(request()->query(self::STATUS_PARAMETER));
    }

    /**
     * The form's status as an enum: the state holds the enum or its value.
     */
    public static function statusFrom(mixed $status): ?MaintenanceStatus
    {
        return match (true) {
            $status instanceof MaintenanceStatus => $status,
            is_string($status) => MaintenanceStatus::tryFrom($status),
            default => null,
        };
    }
}
