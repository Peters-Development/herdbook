<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One raw result. Kept for a while; the roll-ups (CheckStat) keep the rest.
 */
#[Fillable(['check_id', 'checked_at', 'ok', 'response_ms', 'status_code', 'error'])]
class CheckResult extends Model
{
    // Mass: some 150k rows a night, without loading them as models.
    use MassPrunable;

    public $timestamps = false;

    /** @return BelongsTo<Check, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    /** @return Builder<CheckResult> */
    public function prunable(): Builder
    {
        return static::query()->where('checked_at', '<', now()->subDays((int) config('herdbook.checks.keep_results_days')));
    }

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'ok' => 'boolean',
            'response_ms' => 'integer',
            'status_code' => 'integer',
        ];
    }
}
