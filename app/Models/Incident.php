<?php

namespace App\Models;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Concerns\LinksToInventory;
use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonInterval;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outage or problem, with its report, timeline and follow-up actions.
 */
#[Fillable([
    'title', 'severity', 'status', 'occurred_on', 'started_at', 'detected_at', 'resolved_at',
    'impact', 'root_cause', 'body', 'source', 'imported_from',
])]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory, LinksToInventory, RecordsActivity;

    protected $attributes = [
        'source' => 'manual',
    ];

    /** @return HasMany<IncidentTimelineEntry, $this> */
    public function timelineEntries(): HasMany
    {
        return $this->hasMany(IncidentTimelineEntry::class)->orderBy('occurred_at')->orderBy('id');
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
