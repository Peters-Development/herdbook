<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\DeleteConsequences;
use App\Models\Check;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteConsequences::project(),
        ];
    }

    private ?ProjectStatus $statusBeforeSave = null;

    protected function beforeSave(): void
    {
        $this->statusBeforeSave = $this->record->status;
    }

    /**
     * Archiving pauses the project's checks. Unarchiving does not resume them
     * (some may have been paused on purpose), so say how many are still paused.
     */
    protected function afterSave(): void
    {
        if ($this->statusBeforeSave !== ProjectStatus::Archived || $this->record->status === ProjectStatus::Archived) {
            return;
        }

        $paused = Check::query()
            ->where('enabled', false)
            ->whereIn('hostname_id', $this->record->hostnames()->select('hostnames.id'))
            ->count();

        if ($paused > 0) {
            Notification::make()
                ->warning()
                ->title(trans_choice(':count check is still paused|:count checks are still paused', $paused))
                ->body(__('Resume them under Inventory → Checks (filter by this project).'))
                ->persistent()
                ->send();
        }
    }
}
