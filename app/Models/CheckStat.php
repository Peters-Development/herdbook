<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Results rolled up per hour or per day (granularity "hour" / "day").
 */
#[Fillable(['check_id', 'granularity', 'period_start', 'up_count', 'down_count', 'avg_ms', 'min_ms', 'max_ms'])]
class CheckStat extends Model
{
    // Mass: some 150k rows a night, without loading them as models.
    use MassPrunable;

    public const string HOUR = 'hour';

    public const string DAY = 'day';

    public $timestamps = false;

    /** @return BelongsTo<Check, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    /**
     * Hours go after a while; days stay.
     *
     * @return Builder<CheckStat>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('granularity', self::HOUR)
            ->where('period_start', '<', now()->subDays((int) config('herdbook.checks.keep_hour_stats_days')));
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'up_count' => 'integer',
            'down_count' => 'integer',
            'avg_ms' => 'integer',
            'min_ms' => 'integer',
            'max_ms' => 'integer',
        ];
    }
}
