<?php

namespace App\Services;

use App\Models\Contracts\Renewable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Which renewables (Renewable::MODELS) need attention today.
 * The status is derived from dates and lead times (HasRenewal), so it is
 * computed here rather than queried; a prefilter on the expiry date keeps
 * it small.
 */
final class RenewalAttention
{
    /**
     * Lead times longer than this are not supported (the form allows 365).
     */
    private const int MAX_LEAD_DAYS = 365;

    /**
     * Per model and day; the service is scoped (AppServiceProvider), so this
     * lives for one request or one queue job.
     *
     * @var array<string, Collection<int, Model&Renewable>>
     */
    private array $computed = [];

    /**
     * @template TModel of Model&Renewable
     *
     * @param  class-string<TModel>  $model
     * @return Collection<int, TModel>
     */
    public function for(string $model, ?CarbonInterface $today = null): Collection
    {
        $today ??= now(config('herdbook.display_timezone'));

        return $this->computed[$model.'@'.$today->toDateString()] ??= $model::query()
            ->with($model::renewalProviderRelation())
            ->withContract()
            ->whereNull('renewal_stopped_at')
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $today->toImmutable()->addDays(self::MAX_LEAD_DAYS)->toDateString())
            ->get()
            ->filter(fn (Renewable $item): bool => $item->renewalStatus($today)->needsAttention())
            ->values();
    }

    /**
     * Of every kind, the one that expires first first.
     *
     * @return Collection<int, Model&Renewable>
     */
    public function all(?CarbonInterface $today = null): Collection
    {
        return collect(Renewable::MODELS)
            ->flatMap(fn (string $model): Collection => $this->for($model, $today))
            ->sortBy(fn (Renewable $item): string => $item->expires_on->toDateString().$item->name)
            ->values();
    }
}
