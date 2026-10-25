<?php

namespace App\Console\Commands;

use App\Models\Contracts\Renewable;
use App\Services\Alerts\AlertChannels;
use App\Services\Alerts\AlertFailed;
use App\Services\Alerts\AlertMessage;
use App\Services\Costs\Money;
use App\Services\RenewalAttention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Weekday mornings (routes/console.php), but once a week: one message with
 * the renewals that need attention and what they cost, on the first channel
 * that delivers (ntfy, email, Pushover: AlertChannels). Monday sends it; a
 * Monday on which sending failed is made up for on Tuesday. Nothing is sent
 * when nothing needs attention.
 */
class SendRenewalDigest extends Command
{
    protected $signature = 'renewals:digest {--force : Send even when the digest of this week was sent already}';

    protected $description = 'Send the weekly overview of renewals that need attention';

    public function handle(RenewalAttention $attention, AlertChannels $channels): int
    {
        $week = now(config('herdbook.display_timezone'))->format('o-\\WW');
        $sentKey = "renewals:digest:{$week}";

        if (! $this->option('force') && Cache::has($sentKey)) {
            $this->components->info("This week's digest went out already.");

            return self::SUCCESS;
        }

        $items = $attention->all();

        if ($items->isEmpty()) {
            $this->components->info('Nothing needs attention.');

            return self::SUCCESS;
        }

        $route = $channels->for(critical: false);

        if ($route === []) {
            $this->components->warn('No notification channel is configured (ntfy, email or Pushover); nothing sent.');

            return self::SUCCESS;
        }

        $lines = $items->map(function (Renewable $item): string {
            $line = __(':name: :status (expires :date)', [
                'name' => $item->name,
                'status' => $item->renewalStatus()->getLabel(),
                'date' => $item->expires_on->translatedFormat(__('formats.date')),
            ]);

            return ($cost = $this->cost($item)) ? "{$line}, {$cost}" : $line;
        });

        try {
            $channels->sendVia($route, new AlertMessage(
                title: trans_choice(':count renewal needs attention|:count renewals need attention', $items->count()),
                body: $lines->implode("\n"),
                icon: 'calendar',
            ));
        } catch (AlertFailed $failure) {
            $this->components->error("Not sent: {$failure->getMessage()}");

            return self::FAILURE;
        }

        Cache::put($sentKey, true, now()->addDays(8));
        $this->components->info("{$items->count()} renewals reported.");

        return self::SUCCESS;
    }

    /**
     * What the renewal costs: "€19.90 for 2 years", or the monthly amount.
     */
    private function cost(Renewable $item): ?string
    {
        $renewal = $item->renewalCost();

        return $renewal === null
            ? Money::describe($item)
            : __(':amount for :term', ['amount' => Money::format($renewal), 'term' => $item->renewalTerm()]);
    }
}
