<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A follow-up from an incident ("monitor NFS latency").
 */
#[Fillable(['incident_id', 'description', 'due_on', 'done_at'])]
class IncidentAction extends Model
{
    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @param  Builder<IncidentAction>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('done_at');
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /**
     * Past its due date, counted in calendar days in the display timezone.
     */
    public function isOverdue(): bool
    {
        return ! $this->isDone()
            && $this->due_on !== null
            && $this->due_on->toDateString() < now(config('herdbook.display_timezone'))->toDateString();
    }

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'done_at' => 'datetime',
        ];
    }
}
