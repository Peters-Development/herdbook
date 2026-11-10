<?php

namespace App\Filament\Resources\Maintenances\Pages;

use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Support\MaintenanceSchema;
use App\Models\Maintenance;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;

class EditMaintenance extends EditRecord
{
    use DetectsConcurrencyErrors;

    protected static string $resource = MaintenanceResource::class;

    /**
     * Maintenance::version() as the form was filled: saving over a change
     * made since (an agent completed it, another tab) would undo it.
     */
    #[Locked]
    public ?string $filledVersion = null;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * Before the form is filled: version() loads the links, and the form's
     * link fields then fill from those, so both see the same ones.
     */
    protected function beforeFill(): void
    {
        $this->filledVersion = $this->getRecord()->version();
    }

    /**
     * On the locked row and links, inside the transaction the panel puts
     * every save in (databaseTransactions() in AdminPanelProvider): nothing
     * can slip in between this check and the save.
     */
    protected function beforeSave(): void
    {
        if (Maintenance::locked($this->getRecord()->getKey())?->version() === $this->filledVersion) {
            return;
        }

        MaintenanceSchema::changedMeanwhile();

        $this->halt();
    }

    /**
     * A deadlock with another save at the same moment, which the locks of
     * Maintenance::locked() accept (the agent tools try again): nothing was
     * saved, and the form keeps what was typed to be saved again.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (QueryException $exception) {
            if (! $this->causedByConcurrencyError($exception)) {
                throw $exception;
            }

            // The save that was rolled back left its values on the model as if stored.
            $this->getRecord()->refresh();

            Notification::make()
                ->warning()
                ->title(__('Someone saved at the same moment.'))
                ->body(__('Nothing was saved. Save again.'))
                ->send();
        }
    }

    protected function afterSave(): void
    {
        // The saving hook may have made the title or moved the start: the
        // form shows what is stored, so a second save doesn't write it back.
        $this->refreshFormData(['title', 'starts_at']);
        $this->filledVersion = Maintenance::locked($this->getRecord()->getKey())?->version();
    }
}
