<?php

namespace App\Models;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceTask;
use App\Models\Concerns\LinksToInventory;
use App\Models\Concerns\RecordsActivity;
use Database\Factories\MaintenanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Work on servers and projects, planned ahead or written down afterwards:
 * from a quick apt upgrade to a big upgrade with a plan and a report.
 */
#[Fillable(['title', 'tasks', 'status', 'starts_at', 'ends_at', 'plan', 'report', 'user_id'])]
class Maintenance extends Model
{
    /** @use HasFactory<MaintenanceFactory> */
    use HasFactory, LinksToInventory, RecordsActivity;

    protected $attributes = [
        'status' => 'done',
    ];

    protected static function booted(): void
    {
        static::creating(function (Maintenance $maintenance): void {
            $maintenance->user_id ??= auth()->id();
        });

        // A quick entry needs no title: "Updates and reboot". One made that
        // way follows the work when it changes; a typed one stays.
        static::saving(function (Maintenance $maintenance): void {
            // The forms refuse done work in the future, but a local time in the
            // hour that occurs twice (end of summer time) can still land there.
            if ($maintenance->status === MaintenanceStatus::Done && $maintenance->starts_at?->isFuture()) {
                $maintenance->starts_at = now();
            }

            $generated = $maintenance->exists && $maintenance->isDirty('tasks')
                && MaintenanceTask::isDescription($maintenance->title, $maintenance->getOriginal('tasks') ?? []);

            if (blank($maintenance->title) || $generated) {
                $maintenance->title = MaintenanceTask::describe($maintenance->tasks ?? []);
            }
        });
    }

    /**
     * Who wrote it down.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Planned, and its time (the end of its window, or else its start) has
     * passed without it being done or called off.
     */
    public function isOverdue(): bool
    {
        return $this->status === MaintenanceStatus::Planned && ($this->ends_at ?? $this->starts_at)->isPast();
    }

    /** @param  Builder<Maintenance>  $query */
    public function scopePlanned(Builder $query): void
    {
        $query->where('status', MaintenanceStatus::Planned);
    }

    /**
     * The same rule as isOverdue(), in the query.
     *
     * @param  Builder<Maintenance>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->planned()->whereRaw('coalesce(ends_at, starts_at) < ?', [now()]);
    }

    /**
     * By when it is due: the end of its window, or else its start. Overdue
     * work comes before a window that is still open.
     *
     * @param  Builder<Maintenance>  $query
     */
    public function scopeOrderByDue(Builder $query): void
    {
        $query->orderByRaw('coalesce(ends_at, starts_at)');
    }

    /** @param  Builder<Maintenance>  $query */
    public function scopeDone(Builder $query): void
    {
        $query->where('status', MaintenanceStatus::Done);
    }

    protected function linkMorphName(): string
    {
        return 'maintainable';
    }

    /** @return list<string> */
    protected function attributesNotLogged(): array
    {
        return ['plan', 'report'];
    }

    protected function casts(): array
    {
        return [
            'tasks' => 'array',
            'status' => MaintenanceStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
