<?php

namespace App\Filament\Resources\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;

/**
 * An edit page that doesn't undo a change made since it was filled (an
 * agent, monitoring, another tab): it compares the record's version() on
 * the locked row before it saves (GuardsChanges). For a record whose model
 * uses GuardsChanges. It takes the page's beforeFill, beforeSave and
 * afterSave hooks: a page that needs one of its own aliases this one
 * (use GuardsConcurrentEdits { afterSave as guardAfterSave; }) and calls it.
 */
trait GuardsConcurrentEdits
{
    use DetectsConcurrencyErrors;

    /**
     * The record's version() as the form was filled.
     */
    #[Locked]
    public ?string $filledVersion = null;

    /**
     * Tells the person that the record changed since the form was filled.
     */
    abstract protected function changedMeanwhile(): void;

    /**
     * Fields the model's hooks may change as it is saved: the form shows what
     * is stored, so a second save doesn't write the old values back.
     *
     * @return list<string>
     */
    protected function refreshedOnSave(): array
    {
        return [];
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
        if ($this->getRecord()::locked($this->getRecord()->getKey())?->version() === $this->filledVersion) {
            return;
        }

        $this->changedMeanwhile();

        $this->halt();
    }

    /**
     * A deadlock with another save at the same moment, which the locks of
     * locked() accept (the agent tools try again): nothing was saved, and
     * the form keeps what was typed to be saved again.
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
        if (($fields = $this->refreshedOnSave()) !== []) {
            $this->refreshFormData($fields);
        }

        $this->filledVersion = $this->getRecord()::locked($this->getRecord()->getKey())?->version();
    }
}
