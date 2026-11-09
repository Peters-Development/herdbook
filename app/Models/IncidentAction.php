<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuthor;
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
    use RecordsAuthor;

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
     * Who completed it.
     *
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    /**
     * Who completed it, and through which agent: "Ada via deploy-agent".
     * Eager-load: completedBy.
     */
    public function completedByline(): ?string
    {
        return AgentToken::byline($this->completedBy?->name, $this->completed_via);
    }

    /**
     * Done now, by the account signed in and its agent; only if it isn't
     * done yet, so done twice it keeps who did it first and when.
     *
     * @return bool whether this call completed it
     */
    public function complete(): bool
    {
        $completed = self::query()->whereKey($this->getKey())->whereNull('done_at')->update([
            'done_at' => now(),
            'completed_by_id' => auth()->id(),
            'completed_via' => AgentToken::current()?->name,
        ]) === 1;

        $this->refresh();

        return $completed;
    }

    public function reopen(): void
    {
        $this->forceFill(['done_at' => null, 'completed_by_id' => null, 'completed_via' => null])->save();
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
