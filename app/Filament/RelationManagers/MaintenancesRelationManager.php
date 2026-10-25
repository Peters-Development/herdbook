<?php

namespace App\Filament\RelationManagers;

use App\Enums\MaintenanceStatus;
use App\Filament\RelationManagers\Concerns\CreatesLinkedRecords;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\Maintenances\Schemas\MaintenanceForm;
use App\Filament\Support\MaintenanceSchema;
use App\Models\Maintenance;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Maintenance linked to a server or project. "Log" writes down what was
 * just done in a modal; "Schedule" opens the full form, with room for a plan.
 */
class MaintenancesRelationManager extends RelationManager
{
    use CreatesLinkedRecords;

    protected static string $relationship = 'maintenances';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Maintenance');
    }

    /**
     * Dispatched after every change here: the server page above this tab
     * (another component) says when what was last done.
     */
    public const string CHANGED = 'maintenance-changed';

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('maintenance'))
            ->pluralModelLabel(__('maintenance'))
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('starts_at')->label(__('When'))->dateTime(__('formats.datetime'))->sortable(),
                TextColumn::make('title')->label(__('Maintenance'))->searchable()->wrap(),
                MaintenanceSchema::tasksColumn(),
                MaintenanceSchema::statusColumn(),
                TextColumn::make('user.name')->label(__('By'))->placeholder('—')->toggleable(),
            ])
            ->headerActions([
                $this->tellsThePage(CreateAction::make('log'))
                    ->label(__('Log'))
                    ->modalHeading(__('Log maintenance'))
                    ->modalSubmitActionLabel(__('Log'))
                    ->successNotificationTitle(__('Maintenance logged'))
                    ->createAnother(false)
                    ->schema([
                        ...MaintenanceSchema::workFields(),
                        MaintenanceSchema::doneAt(__('When'))->default(fn () => now()),
                        MaintenanceSchema::report(),
                    ])
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'status' => MaintenanceStatus::Done]),
                $this->createLinkedAction(MaintenanceResource::class, __('Schedule'), [MaintenanceForm::STATUS_PARAMETER => MaintenanceStatus::Planned->value]),
                // For one maintenance on more servers; the newest first, with their date.
                $this->tellsThePage(AttachAction::make())
                    ->label(__('Link'))
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['title'])
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query->latest('starts_at'))
                    ->recordTitle(fn (Maintenance $record): string => MaintenanceSchema::titleWithDate($record)),
            ])
            ->recordUrl(fn (Maintenance $record): string => MaintenanceResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                $this->tellsThePage(MaintenanceSchema::completeAction()),
                $this->tellsThePage(MaintenanceSchema::cancelAction()),
                $this->tellsThePage(DetachAction::make())->label(__('Unlink')),
            ]);
    }

    /**
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @return TAction
     */
    private function tellsThePage(Action $action): Action
    {
        return $action->after(fn () => $this->dispatch(self::CHANGED));
    }
}
