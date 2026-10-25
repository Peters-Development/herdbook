<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\confirm;

/**
 * The demo data freezes a few minutes after seeding (its checks never run,
 * so the overview reports them as stalled). This empties everything but the
 * accounts and seeds it again: a fresh picture without setting up two-factor
 * again.
 */
class ResetDemo extends Command
{
    /**
     * Children first, so no foreign key is in the way.
     */
    private const array TABLES = [
        'check_events', 'check_results', 'check_stats', 'checks',
        'incidentables', 'documentables', 'credentialables',
        'incident_timeline_entries', 'incident_actions', 'incidents',
        'maintainables', 'maintenances',
        'document_revisions', 'documents', 'credential_references',
        'hostnames', 'environment_server', 'server_dependencies', 'environments',
        'domains', 'subscriptions', 'projects', 'servers', 'network_addresses', 'networks', 'clients', 'providers',
        'activity_log',
    ];

    protected $signature = 'demo:reset {--force : Do not ask for confirmation}';

    protected $description = 'Empty the inventory and fill it with the demo data again (local only)';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->components->error('The demo data is for local development only.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! confirm('Delete everything except the accounts, and seed the demo data?', default: false)) {
            return self::FAILURE;
        }

        DB::transaction(function (): void {
            foreach (self::TABLES as $table) {
                DB::table($table)->delete();
            }
        });

        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        return self::SUCCESS;
    }
}
