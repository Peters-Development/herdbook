<?php

namespace App\Models;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceTask;
use App\Models\Concerns\LinksToInventory;
use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\MaintenanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Work on servers and projects, planned ahead or written down afterwards:
 * from a quick apt upgrade to a big upgrade with a plan and a report.
 */
#[Fillable(['title', 'tasks', 'status', 'starts_at', 'ends_at', 'plan', 'report', 'user_id'])]
class Maintenance extends Model
{
    /** @use HasFactory<MaintenanceFactory> */
    use HasFactory, LinksToInventory, RecordsActivity;

    /**
     * What complete() takes from its caller.
     */
    public const array COMPLETION_FIELDS = ['starts_at', 'ends_at', 'report'];

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
                $maintenance->starts_at = now()->startOfMinute();
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
     * Who wrote it down, and through which agent ("Ada via deploy-agent").
     * Eager-load: user.
     */
    public function recordedBy(): ?string
    {
        return AgentToken::byline($this->user?->name, $this->recorded_via);
    }

    /**
     * The first moment its timestamp columns hold (MySQL).
     */
    public static function earliestMoment(): CarbonImmutable
    {
        return CarbonImmutable::parse('1970-01-01 00:00:01', 'UTC');
    }

    /**
     * The last moment its timestamp columns hold (MySQL).
     */
    public static function latestMoment(): CarbonImmutable
    {
        return CarbonImmutable::parse('2038-01-19 03:14:07', 'UTC');
    }

    /**
     * What completing it starts from: done before its planned time, it
     * started now; a planned end that hasn't come is no end; its report so far.
     *
     * @return array{starts_at: CarbonImmutable, ends_at: ?CarbonImmutable, report: ?string}
     */
    public function completionDefaults(): array
    {
        return [
            'starts_at' => $this->starts_at->isFuture() ? now()->startOfMinute() : $this->starts_at,
            'ends_at' => $this->ends_at?->isPast() ? $this->ends_at : null,
            'report' => $this->report,
        ];
    }

    /**
     * What completing it stores: starts_at, ends_at and report from $data,
     * completionDefaults() for those it leaves out, and done.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function completionAttributes(array $data): array
    {
        $attributes = [...$this->completionDefaults(), ...Arr::only($data, self::COMPLETION_FIELDS)];

        // The planned end is no end of work that started after it.
        if (! array_key_exists('ends_at', $data) && $attributes['ends_at'] !== null
            && $this->asDateTime($attributes['ends_at'])->lt($this->asDateTime($attributes['starts_at']))) {
            $attributes['ends_at'] = null;
        }

        return [...$attributes, 'status' => MaintenanceStatus::Done];
    }

    /**
     * Planned → done (completionAttributes()). False: it was no longer
     * planned, or no longer $version; the model says what it is now.
     *
     * @param  array<string, mixed>  $data
     * @param  (Closure(array<string, mixed>, self): void)|null  $check  sees what will be stored, on the current row; may throw to refuse
     * @param  string|null  $version  version() as it was read: false when it changed since
     */
    public function complete(array $data, ?Closure $check = null, ?string $version = null): bool
    {
        return $this->updateWhileStill(MaintenanceStatus::Planned, function () use ($data, $check): array {
            $attributes = $this->completionAttributes($data);

            if ($check !== null) {
                $check($attributes, $this);
            }

            return $attributes;
        }, $version);
    }

    /**
     * Planned → called off. False: it was no longer planned, or no longer
     * $version (if given); the model says what it is now.
     */
    public function cancel(?string $version = null): bool
    {
        return $this->updateWhileStill(MaintenanceStatus::Planned, ['status' => MaintenanceStatus::Cancelled], $version);
    }

    /**
     * Updates it only if it still has $status, and still is the $version
     * that was read (if given), from the row as it is now: the row is locked
     * and read first, so a change someone else made meanwhile is neither
     * overwritten nor logged as this one's. Through Eloquent, so the saving
     * hook and the activity log run. Either way the model holds the row as
     * it is afterwards; a row that is gone leaves it not existing.
     *
     * @param  array<string, mixed>|(Closure(): array<string, mixed>)  $attributes  a closure sees the current row
     */
    public function updateWhileStill(MaintenanceStatus $status, array|Closure $attributes, ?string $version = null): bool
    {
        // Again on a deadlock (see locked()), when it is the outermost transaction.
        return DB::transaction(function () use ($status, $attributes, $version): bool {
            $current = self::locked($this->getKey());

            if ($current === null) {
                $this->exists = false;

                return false;
            }

            $this->setRawAttributes($current->getAttributes(), sync: true);
            $this->setRelations($current->getRelations());

            if ($this->status !== $status || ($version !== null && $this->version() !== $version)) {
                return false;
            }

            return $this->fill(value($attributes))->save();
        }, attempts: 3);
    }

    /**
     * The row and its links, locked: what a save over it compares its
     * version() with. Inside a transaction. The links with a locking read
     * too: MySQL's plain reads see the transaction's first snapshot, which
     * an earlier query in it (a form's options) may have fixed already. Its
     * gap locks can make two saves that both add links deadlock: one fails
     * whole, which beats a link change undone in silence, and the agent
     * tools try again.
     */
    public static function locked(int|string $id): ?self
    {
        return self::query()
            ->lockForUpdate()
            ->with(['servers' => fn ($query) => $query->sharedLock(), 'projects' => fn ($query) => $query->sharedLock()])
            ->find($id);
    }

    /**
     * A fingerprint of everything a person or an agent can change, its links
     * included: compared before saving over it, so a change made meanwhile
     * (an agent, another tab) isn't undone. Not updated_at: that counts in
     * seconds, and linking a server doesn't touch it. Keyed, as it covers the
     * whole of a plan an agent only reads the start of. Loads servers and
     * projects if they aren't.
     */
    public function version(): string
    {
        $this->loadMissing(['servers', 'projects']);

        return substr(hash_hmac('sha256', json_encode([
            $this->title,
            $this->tasks,
            $this->status->value,
            $this->starts_at?->toJSON(),
            $this->ends_at?->toJSON(),
            $this->plan,
            $this->report,
            collect($this->servers->modelKeys())->sort()->values()->all(),
            collect($this->projects->modelKeys())->sort()->values()->all(),
        ], JSON_THROW_ON_ERROR), (string) config('app.key')), 0, 16);
    }

    /**
     * The last logged change to the plan and to the report (null: none),
     * from the activity log, which records who changed a text and through
     * which agent, but not the text: so a plan someone rewrote doesn't pass
     * for its creator's. Changes from before Herdbook logged them ("texts")
     * aren't there.
     *
     * @return array{plan: ?Activity, report: ?Activity}
     */
    public function lastTextChanges(): array
    {
        $last = fn (string $text): ?Activity => $this->activitiesAsSubject()
            ->where('event', 'updated')
            ->whereJsonContains('properties->texts', $text)
            ->with('causer')
            ->latest('id')
            ->first();

        return ['plan' => $last('plan'), 'report' => $last('report')];
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

    /**
     * Maintenance that includes $task, among other work.
     *
     * @param  Builder<Maintenance>  $query
     */
    public function scopeIncludesTask(Builder $query, MaintenanceTask|string $task): void
    {
        $query->whereJsonContains($query->qualifyColumn('tasks'), $task instanceof MaintenanceTask ? $task->value : $task);
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
