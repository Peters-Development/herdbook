<?php

namespace App\Filament\RelationManagers;

use App\Filament\Support\ActivityPresenter;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Who changed what, and when (RecordsActivity). Besides the record itself,
 * the parts that belong to it: a project shows its environments and which
 * servers they run on, a server its assignments and dependencies, a domain
 * its hostnames, a network its addresses (stored as activityContext(), so
 * deletes stay visible).
 */
class ActivityRelationManager extends RelationManager
{
    protected static string $relationship = 'activitiesAsSubject';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Activity');
    }

    /**
     * Owner morph alias => activity properties that point at the owner.
     *
     * @var array<string, list<string>>
     */
    private const array CONTEXT_KEYS = [
        'project' => ['project_id'],
        'server' => ['server_id', 'depends_on_id'],
        'domain' => ['domain_id'],
        'hostname' => ['hostname_id'],
        'network' => ['network_id'],
    ];

    public function isReadOnly(): bool
    {
        return true;
    }

    protected function getTableQuery(): Builder
    {
        $owner = $this->getOwnerRecord();

        return Activity::query()
            ->with('causer')
            ->where(function (Builder $query) use ($owner): void {
                $query->where(fn (Builder $query) => $query
                    ->where('subject_type', $owner->getMorphClass())
                    ->where('subject_id', $owner->getKey()));

                foreach (self::CONTEXT_KEYS[$owner->getMorphClass()] ?? [] as $key) {
                    $query->orWhere("properties->{$key}", $owner->getKey());
                }
            });
    }

    public function table(Table $table): Table
    {
        $ownerType = $this->getOwnerRecord()->getMorphClass();

        return $table
            ->modelLabel(__('activity'))
            ->pluralModelLabel(__('activity'))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->dateTime(__('formats.datetime')),
                TextColumn::make('causer.name')->label(__('By'))->placeholder(__('System')),
                TextColumn::make('description')
                    ->label(__('What'))
                    ->state(fn (Activity $record): string => ActivityPresenter::description($record, $ownerType))
                    ->wrap(),
                TextColumn::make('attribute_changes')
                    ->label(__('Changes'))
                    ->state(fn (Activity $record): ?string => ActivityPresenter::changes($record))
                    ->listWithLineBreaks()
                    ->separator("\n")
                    ->wrap()
                    ->placeholder('—'),
            ]);
    }
}
