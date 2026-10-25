<?php

namespace App\Models;

use App\Enums\CheckState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A switch between up and down: what notifications and incidents start from.
 */
#[Fillable(['check_id', 'from_state', 'to_state', 'occurred_at', 'error', 'incident_id', 'incident_evaluated_at', 'notified_at', 'notify_attempts', 'notify_failed'])]
class CheckEvent extends Model
{
    protected $attributes = [
        'notify_attempts' => 0,
        'notify_failed' => false,
    ];

    public $timestamps = false;

    /** @return BelongsTo<Check, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function wentDown(): bool
    {
        return $this->to_state === CheckState::Down;
    }

    protected function casts(): array
    {
        return [
            'from_state' => CheckState::class,
            'to_state' => CheckState::class,
            'occurred_at' => 'datetime',
            'incident_evaluated_at' => 'datetime',
            'notified_at' => 'datetime',
            'notify_attempts' => 'integer',
            'notify_failed' => 'boolean',
        ];
    }
}
