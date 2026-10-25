<?php

namespace App\Filament\RelationManagers\Concerns;

use App\Filament\Support\LinkSchema;
use Filament\Actions\Action;
use Filament\Resources\Resource;

/**
 * "New …" from an inventory page: opens the create page of $resource with
 * the page's record already selected under "Related to".
 */
trait CreatesLinkedRecords
{
    /**
     * @param  class-string<resource>  $resource
     * @param  array<string, string>  $query  more defaults for the create page
     */
    protected function createLinkedAction(string $resource, string $label, array $query = []): Action
    {
        return Action::make('create')
            ->label($label)
            ->url(fn (): string => $resource::getUrl('create', [
                LinkSchema::QUERY_PARAMETER => LinkSchema::linkKey($this->getOwnerRecord()),
                ...$query,
            ]));
    }
}
