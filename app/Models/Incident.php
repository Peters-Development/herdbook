<?php

namespace App\Models;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Concerns\GuardsChanges;
use App\Models\Concerns\LinksToInventory;
use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\RecordsAuthor;
use App\Services\Health\ProjectIncidents;
use Carbon\CarbonInterval;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outage or problem, with its report, timeline and follow-up actions.
 */
#[Fillable([
    'title', 'severity', 'status', 'occurred_on', 'started_at', 'detected_at', 'resolved_at',
    'impact', 'root_cause', 'body', 'source', 'imported_from', 'user_id',
])]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use GuardsChanges, HasFactory, LinksToInventory, RecordsActivity, RecordsAuthor;

    protected $attributes = [
        'source' => 'manual',
    ];

    /**
     * The agent tools write over its root cause and report only whole.
     */
    protected function keepsReplacedTexts(): bool
    {
        return true;
    }

    /**
     * What the edit form and the agent tools can change; not where it came
     * from (source, imported_from) or who wrote it down.
     */
    public static function versionedAttributes(): array
    {
        return ['title', 'severity', 'status', 'occurred_on', 'started_at', 'detected_at', 'resolved_at', 'impact', 'root_cause', 'body'];
    }

    public static function versionedRelations(): array
    {
        return ['projects', 'servers', 'domains', 'networks', 'clients'];
    }

    /** @return HasMany<IncidentTimelineEntry, $this> */
    public function timelineEntries(): HasMany
    {
        return $this->hasMany(IncidentTimelineEntry::class)->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * Linked to any of $serverIds directly, or one of $projectIds's
     * incidents as far as Herdbook counts them for a project
     * (ProjectIncidents::reach(): through the servers its production needs,
     * and their networks).
     *
     * @param  Builder<Incident>  $query
     * @param  list<int>  $serverIds
     * @param  list<int>  $projectIds
     */
    public function scopeConcerning(Builder $query, array $serverIds, array $projectIds): void
    {
        $reach = $projectIds === [] ? null : app(ProjectIncidents::class)->reach($projectIds);

        $query->where(fn (Builder $query) => $query
            ->when($serverIds, fn (Builder $query) => $query->orWhereHas('servers', fn (Builder $servers) => $servers->whereKey($serverIds)))
            ->when($reach, fn (Builder $query) => $query
                ->orWhereHas('projects', fn (Builder $projects) => $projects->whereKey($reach['projects']))
                ->when($reach['servers'], fn (Builder $query, array $ids) => $query->orWhereHas('servers', fn (Builder $servers) => $servers->whereKey($ids)))
                ->when($reach['networks'], fn (Builder $query, array $ids) => $query->orWhereHas('networks', fn (Builder $networks) => $networks->whereKey($ids)))));
    }

    /**
     * Open or monitoring.
     *
     * @param  Builder<Incident>  $query
     */
    public function scopeUnresolved(Builder $query): void
    {
        $query->where('status', '!=', IncidentStatus::Resolved);
    }

    /**
     * Open first, then the most severe (sev1 sorts first), then the latest:
     * as ProjectIncidents orders them.
     *
     * @param  Builder<Incident>  $query
     */
    public function scopeOrderByUrgency(Builder $query): void
    {
        $query->orderByRaw('case when status = ? then 0 else 1 end', [IncidentStatus::Open->value])->orderBy('severity')->orderByDesc('occurred_on')->orderByDesc('id');
    }

    /**
     * The latest $limit timeline entries, newest first, with who wrote them.
     *
     * @return Collection<int, IncidentTimelineEntry>
     */
    public function latestTimelineEntries(int $limit): Collection
    {
        return $this->timelineEntries()->reorder()->latest('occurred_at')->latest('id')->with('user')->limit($limit)->get();
    }

    /** @return HasMany<IncidentAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(IncidentAction::class);
    }

    /**
     * From start to resolution; null while either is unknown.
     */
    public function duration(): ?CarbonInterval
    {
        return $this->started_at && $this->resolved_at
            ? $this->started_at->diffAsCarbonInterval($this->resolved_at)
            : null;
    }

    /**
     * How long the incident went unnoticed.
     */
    public function timeToDetect(): ?CarbonInterval
    {
        return $this->started_at && $this->detected_at
            ? $this->started_at->diffAsCarbonInterval($this->detected_at)
            : null;
    }

    protected function linkMorphName(): string
    {
        return 'incidentable';
    }

    /** @return list<string> */
    protected function attributesNotLogged(): array
    {
        return ['body', 'root_cause'];
    }

    protected function casts(): array
    {
        return [
            'severity' => IncidentSeverity::class,
            'status' => IncidentStatus::class,
            'source' => IncidentSource::class,
            'occurred_on' => 'date',
            'started_at' => 'datetime',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
