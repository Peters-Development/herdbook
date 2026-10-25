<?php

namespace App\Console\Commands;

use App\Jobs\RunChecks;
use App\Models\Check;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Every minute (routes/console.php): hands the due checks to the queue.
 * HTTP checks run concurrently, so they go in large batches; the others run
 * one by one inside a job, so their batches stay small enough for its timeout.
 */
class DispatchChecks extends Command
{
    private const int HTTP_BATCH = 20;

    /**
     * Each of these can take up to ~15 s in the worst case (a name that does
     * not resolve, then a connect time-out), and the job stops at 50 s.
     */
    private const int OTHER_BATCH = 2;

    protected $signature = 'checks:dispatch';

    protected $description = 'Queue the checks that are due';

    public function handle(): int
    {
        // From the start of the minute: a run that starts a little later next
        // time must still find the check due.
        $now = Date::now()->startOfMinute();

        $due = Check::query()
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', Date::now()))
            ->get(['id', 'type', 'interval_seconds', 'state']);

        // Moved forward first, so a slow queue never gets the same check twice.
        $due->groupBy(fn (Check $check): int => $check->effectiveInterval())
            ->each(fn (Collection $checks, int $seconds) => Check::query()->whereKey($checks->modelKeys())->update(['next_check_at' => $now->addSeconds($seconds)]));

        [$http, $other] = $due->partition(fn (Check $check): bool => $check->type->isHttp());

        $http->chunk(self::HTTP_BATCH)->each(fn (Collection $batch) => RunChecks::dispatch($batch->modelKeys()));
        $other->chunk(self::OTHER_BATCH)->each(fn (Collection $batch) => RunChecks::dispatch($batch->modelKeys()));

        $this->components->info("{$due->count()} checks queued.");

        return self::SUCCESS;
    }
}
