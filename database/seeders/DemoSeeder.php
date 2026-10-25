<?php

namespace Database\Seeders;

use App\Enums\CheckState;
use App\Enums\CheckType;
use App\Enums\CostPeriod;
use App\Enums\EnvironmentKind;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceTask;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\ProviderKind;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Check;
use App\Models\CheckResult;
use App\Models\CheckStat;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Network;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerDependency;
use App\Models\Subscription;
use App\Services\Checks\DefaultChecks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;
use RuntimeException;

/**
 * Made-up projects, servers, networks, domains and a day of check history, to design
 * and try the dashboard, the map and the status screen on:
 *
 *     php artisan demo:reset
 *
 * (or `php artisan db:seed --class=DemoSeeder` on an empty inventory)
 *
 * Never in production, and only into an empty inventory: it must not mix
 * with real data. Its checks are never scheduled (their names do not exist),
 * so nothing goes out on the network.
 */
class DemoSeeder extends Seeder
{
    use Localizable;

    private CarbonImmutable $now;

    /** @var array<string, Provider> */
    private array $providers = [];

    /** @var array<string, Server> */
    private array $servers = [];

    /** @var array<string, Domain> */
    private array $domains = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo data is for development, not for production.');
        }

        $inventory = [Project::class, Server::class, Network::class, Domain::class, Subscription::class, Provider::class, Client::class, Check::class, Incident::class, Maintenance::class];

        if (collect($inventory)->contains(fn (string $model): bool => $model::query()->exists())) {
            throw new RuntimeException('The inventory is not empty: the demo data only goes into an empty one (migrate:fresh first).');
        }

        $this->now = Date::now()->utc()->startOfMinute();

        // English, whatever the default language: environment kinds and check
        // names come from translations, the rest of the demo is English too.
        $this->withLocale('en', fn () => DB::transaction(function (): void {
            $this->providers();
            $this->servers();
            $this->domains();
            $this->projects();
            $this->subscriptions();
            $this->maintenance();
            $this->serverChecks();
            $this->history();
        }));

        // The hour stats of the last day, from the raw results just written.
        Artisan::call('checks:aggregate', ['--hours' => 24]);
    }

    private function providers(): void
    {
        foreach ([
            'DigitalOcean' => [ProviderKind::Hosting],
            'Hetzner' => [ProviderKind::Hosting],
            'Gandi' => [ProviderKind::Registrar, ProviderKind::Dns],
            'Cloudflare' => [ProviderKind::Dns],
            'Namecheap' => [ProviderKind::Registrar, ProviderKind::Dns],
            'Vodafone' => [ProviderKind::Internet],
            'JetBrains' => [ProviderKind::Other],
            'Google' => [ProviderKind::Mail],
            'Postmark' => [ProviderKind::Mail],
            'Plausible' => [ProviderKind::Other],
        ] as $name => $kinds) {
            $this->providers[$name] = Provider::create(['name' => $name, 'kinds' => array_map(fn (ProviderKind $kind): string => $kind->value, $kinds)]);
        }
    }

    private function servers(): void
    {
        foreach ([
            'herd-app' => ['DigitalOcean', '203.0.113.10', 'Premium AMD 8 GB', 34.95, 30],
            'herd-storage' => ['DigitalOcean', '203.0.113.11', 'Basic 2 GB + 500 GB volume', 12.50, 30],
            'herd-db' => ['DigitalOcean', '203.0.113.12', 'Premium AMD 4 GB', 24.95, 30],
            'web-01' => ['Hetzner', '198.51.100.21', 'CX32', 7.59, 200],
            'web-02' => ['Hetzner', '198.51.100.22', 'CX32', 7.59, 200],
            'staging-01' => ['Hetzner', '198.51.100.30', 'CX22', 4.59, 200],
            'mail-01' => ['Hetzner', '198.51.100.40', 'CX22', 4.59, 200],
            'legacy-01' => ['DigitalOcean', '203.0.113.99', 'Basic 4 GB', 14.95, null],
        ] as $name => [$provider, $ip, $plan, $cost, $expiresInDays]) {
            $this->servers[$name] = Server::create([
                'provider_id' => $this->providers[$provider]->id,
                'name' => $name,
                'status' => match ($name) {
                    'mail-01' => ServerStatus::Maintenance,
                    'legacy-01' => ServerStatus::Decommissioned,
                    default => ServerStatus::Active,
                },
                'plan' => $plan,
                'public_ipv4' => $ip,
                'os_name' => 'Ubuntu',
                'os_version' => $name === 'legacy-01' ? '20.04' : '24.04',
                'ssh_user' => 'deploy',
                'cost_amount' => $cost,
                'cost_period' => CostPeriod::Month,
                'expires_on' => $expiresInDays === null ? null : $this->today()->addDays($expiresInDays)->toDateString(),
                'auto_renews' => true,
                'renewal_period_months' => 1,
            ]);
        }

        // Our own, in the office: no provider, no contract, only a private
        // address. It hangs from the office network instead.
        $office = Network::create([
            'name' => 'Main office',
            'provider_id' => $this->providers['Vodafone']->id,
            'location' => 'Main office',
            'subnet' => '192.168.1.0/24',
            'gateway' => '192.168.1.1',
            'public_ip' => '192.0.2.44',
            'cost_amount' => '49.50',
            'cost_period' => CostPeriod::Month,
            'expires_on' => $this->today()->addDays(150)->toDateString(),
            'renewal_period_months' => 12,
            'notes' => "Gateway with the controller on it. DHCP from .100, fixed addresses below that.\n\nGuest network: its own VLAN, cannot reach office-01.",
        ]);

        foreach ([
            ['192.168.1.1', 'Gateway', '02:00:5e:12:34:56', 'Also runs the controller'],
            ['192.168.1.2', 'Office switch', null, null],
            ['192.168.1.20', 'NAS', null, 'Backups of office-01'],
            ['192.168.1.30', 'Printer', null, null],
        ] as [$address, $name, $mac, $notes]) {
            $office->addresses()->create(['address' => $address, 'name' => $name, 'mac_address' => $mac, 'notes' => $notes]);
        }

        $this->servers['office-01'] = Server::create([
            'network_id' => $office->id,
            'name' => 'office-01',
            'location' => 'Main office',
            'private_ip' => '192.168.1.10',
            'os_name' => 'Ubuntu',
            'os_version' => '24.04',
            'ssh_user' => 'admin',
        ]);

        $this->dependsOn('herd-app', 'herd-storage', 'NFS');
        $this->dependsOn('herd-app', 'herd-db', 'WireGuard');
        $this->dependsOn('web-02', 'herd-db', 'WireGuard');
    }

    private function domains(): void
    {
        $bakery = Client::create(['name' => 'Meadow Bakery', 'contact_name' => 'Anna Brooks', 'contact_phone' => '+44 7700 900142']);

        // The last one has no amount yet: the cost page asks for it.
        foreach ([
            'studbook.test' => ['Gandi', 'Cloudflare', 40, true, null, '9.95'],
            'pastureplanner.test' => ['Gandi', 'Gandi', 10, false, null, '9.95'],
            'meadowbakery.test' => ['Namecheap', 'Namecheap', 75, true, $bakery, '12.99'],
            'cartercycles.test' => ['Namecheap', 'Namecheap', -3, false, null, '12.99'],
            'freerange.test' => ['Gandi', 'Cloudflare', 120, true, null, '9.95'],
            'herdbook-demo.test' => ['Gandi', 'Cloudflare', 300, true, null, null],
        ] as $name => [$registrar, $dns, $expiresInDays, $autoRenews, $client, $cost]) {
            $this->domains[$name] = Domain::create([
                'name' => $name,
                'client_id' => $client?->id,
                'registrar_id' => $this->providers[$registrar]->id,
                'dns_provider_id' => $this->providers[$dns]->id,
                'cost_amount' => $cost,
                'cost_period' => $cost === null ? null : CostPeriod::Year,
                'expires_on' => $this->today()->addDays($expiresInDays)->toDateString(),
                'auto_renews' => $autoRenews,
                'renewal_period_months' => 12,
            ]);
        }
    }

    /**
     * What else is paid for: monthly and yearly, one to decide on, one
     * stopped, for a project and for a client.
     */
    private function subscriptions(): void
    {
        $project = fn (string $name): Project => Project::query()->where('name', $name)->firstOrFail();

        foreach ([
            ['JetBrains All Products', 'JetBrains', null, '289.00', CostPeriod::Year, 45, true, null],
            ['Google Workspace', 'Google', 'Business Starter', '6.80', CostPeriod::Month, null, true, null],
            ['Postmark', 'Postmark', '10,000 emails', '15.00', CostPeriod::Month, null, true, 'Studbook'],
            ['Plausible Analytics', 'Plausible', 'Growth', '90.00', CostPeriod::Year, 20, false, 'Meadow Bakery webshop'],
            ['Old mailbox', 'Google', null, '4.00', CostPeriod::Month, 40, true, null],
        ] as [$name, $provider, $plan, $cost, $period, $expiresInDays, $autoRenews, $projectName]) {
            Subscription::create([
                'name' => $name,
                'provider_id' => $this->providers[$provider]->id,
                'plan' => $plan,
                'project_id' => $projectName === null ? null : $project($projectName)->id,
                'cost_amount' => $cost,
                'cost_period' => $period,
                'expires_on' => $expiresInDays === null ? null : $this->today()->addDays($expiresInDays)->toDateString(),
                'auto_renews' => $autoRenews,
                'renewal_period_months' => $period === CostPeriod::Month ? 1 : 12,
                'renewal_stopped_at' => $name === 'Old mailbox' ? $this->now : null,
            ]);
        }
    }

    private function projects(): void
    {
        $carter = Client::create(['name' => 'Carter Cycles']);
        $freeRange = Client::create(['name' => 'Free Range Foundation', 'contact_name' => 'Peter Woods', 'contact_email' => 'peter@freerange.test']);
        $bakery = Client::query()->where('name', 'Meadow Bakery')->firstOrFail();

        $studbook = $this->project('Studbook', ProjectType::Product, tags: ['Laravel', 'Filament'], onScreen: true);
        $production = $this->environment($studbook, EnvironmentKind::Production, ['herd-app' => ServerRole::App, 'herd-storage' => ServerRole::Storage, 'herd-db' => ServerRole::Database]);
        $this->monitor($this->hostname('app.studbook.test', $production, 'app'));
        $this->monitor($this->hostname('api.studbook.test', $production, 'api'));
        $staging = $this->environment($studbook, EnvironmentKind::Staging, ['staging-01' => ServerRole::App]);
        $this->monitor($this->hostname('staging.studbook.test', $staging, 'app'), [CheckType::Http->value => [CheckState::Down, 95]]);

        $planner = $this->project('Pasture Planner', ProjectType::Product, tags: ['Laravel', 'Livewire'], onScreen: true);
        $production = $this->environment($planner, EnvironmentKind::Production, ['herd-app' => ServerRole::App, 'herd-db' => ServerRole::Database]);
        $this->monitor($this->hostname('pastureplanner.test', $production, 'website'), [CheckType::Http->value => [CheckState::Down, 14]]);
        $this->monitor($this->hostname('www.pastureplanner.test', $production, 'website', behindProxy: true));
        $this->environment($planner, EnvironmentKind::Staging, ['staging-01' => ServerRole::App]);
        $this->incident($planner, 'Pasture Planner returns a 502', IncidentSeverity::Sev1, IncidentStatus::Open, minutesAgo: 14);
        $this->incident($planner, 'Queue filled up after a deploy', IncidentSeverity::Sev2, IncidentStatus::Resolved, minutesAgo: 60 * 24 * 12);

        $webshop = $this->project('Meadow Bakery webshop', ProjectType::ClientProject, $bakery, ['WooCommerce'], onScreen: true);
        $production = $this->environment($webshop, EnvironmentKind::Production, ['web-01' => ServerRole::App]);
        $this->monitor($this->hostname('meadowbakery.test', $production, 'website'), [CheckType::Tls->value => [CheckState::Down, 60 * 30]]);
        $this->monitor($this->hostname('www.meadowbakery.test', $production, 'website', redirectsTo: 'meadowbakery.test'));

        $site = $this->project('Carter Cycles website', ProjectType::Website, $carter, ['WordPress'], onScreen: true);
        $production = $this->environment($site, EnvironmentKind::Production, ['web-02' => ServerRole::App]);
        $this->monitor($this->hostname('cartercycles.test', $production, 'website'));

        $portal = $this->project('Free Range portal', ProjectType::ClientProject, $freeRange, ['Laravel'], onScreen: true);
        $production = $this->environment($portal, EnvironmentKind::Production, ['web-02' => ServerRole::App, 'herd-db' => ServerRole::Database]);
        $this->monitor($this->hostname('portal.freerange.test', $production, 'app'), [CheckType::Http->value => [CheckState::Pending, 1]]);
        $this->environment($portal, EnvironmentKind::Staging, ['staging-01' => ServerRole::App]);
        $this->incident($portal, 'Slow logins because of a full disk', IncidentSeverity::Sev3, IncidentStatus::Resolved, minutesAgo: 60 * 24 * 40);

        // Nothing watches it: no checks on its name, none on its server.
        $tools = $this->project('Internal tools', ProjectType::Internal);
        $production = $this->environment($tools, EnvironmentKind::Production, ['mail-01' => ServerRole::Other]);
        $this->hostname('tools.herdbook-demo.test', $production, 'admin');

        // Needs no checks: it runs on the office network, next to the host that does the checking.
        $wiki = $this->project('Office wiki', ProjectType::Internal, unmonitored: true);
        $this->environment($wiki, EnvironmentKind::Production, ['office-01' => ServerRole::App]);

        $newsletter = $this->project('Newsletter', ProjectType::Product, status: ProjectStatus::Maintenance, onScreen: true);
        $production = $this->environment($newsletter, EnvironmentKind::Production, ['mail-01' => ServerRole::Mail]);
        $this->monitor($this->hostname('mail.herdbook-demo.test', $production, 'mail'));
        Check::query()->whereIn('hostname_id', $newsletter->hostnames()->select('hostnames.id'))->get()->each->update(['enabled' => false]);

        $this->project('Old archive', ProjectType::Internal, status: ProjectStatus::Archived);
    }

    /**
     * Quick entries, an inspection with a note, a planned upgrade with a
     * plan, and planned updates that were never ticked off (overdue).
     */
    private function maintenance(): void
    {
        $this->maintain(['herd-app'], [MaintenanceTask::Updates, MaintenanceTask::Reboot], MaintenanceStatus::Done, $this->now->subDays(3));
        $this->maintain(['herd-storage'], [MaintenanceTask::Inspection], MaintenanceStatus::Done, $this->now->subDays(10), report: 'Went through the logs, nothing unusual. Disk at 61%.');
        $this->maintain(['office-01'], [MaintenanceTask::Updates], MaintenanceStatus::Done, $this->now->subDays(40));
        $this->maintain(['staging-01'], [MaintenanceTask::Updates], MaintenanceStatus::Planned, $this->now->subDays(2));

        $upgrade = $this->maintain(['web-01', 'web-02'], [MaintenanceTask::Upgrade, MaintenanceTask::Reboot], MaintenanceStatus::Planned, $this->today()->addDays(9)->setTime(22, 0)->utc(), 'Upgrade to Ubuntu 26.04');
        $upgrade->update([
            'ends_at' => $upgrade->starts_at->addHours(3),
            'plan' => "1. Take a snapshot at the provider.\n2. `do-release-upgrade` on web-02, then go through the sites.\n3. The same on web-01.\n\nRollback: restore the snapshot.",
        ]);
        $upgrade->projects()->attach(Project::query()->whereIn('name', ['Meadow Bakery webshop', 'Carter Cycles website'])->pluck('id'));
    }

    /**
     * @param  list<string>  $servers
     * @param  list<MaintenanceTask>  $tasks
     */
    private function maintain(array $servers, array $tasks, MaintenanceStatus $status, CarbonImmutable $startsAt, ?string $title = null, ?string $report = null): Maintenance
    {
        $maintenance = Maintenance::create([
            'title' => $title,
            'tasks' => array_map(fn (MaintenanceTask $task): string => $task->value, $tasks),
            'status' => $status,
            'starts_at' => $startsAt,
            'report' => $report,
        ]);

        $maintenance->servers()->attach(array_map(fn (string $server): int => $this->servers[$server]->id, $servers));

        return $maintenance;
    }

    private function serverChecks(): void
    {
        foreach (['herd-app', 'herd-storage', 'herd-db', 'web-01', 'web-02', 'staging-01'] as $name) {
            $server = $this->servers[$name];
            $this->settle(Check::create([
                'server_id' => $server->id,
                'type' => CheckType::Ping,
                'target' => $server->public_ipv4,
                'interval_seconds' => 60,
            ]), CheckState::Up);
        }
    }

    /**
     * A day of raw results every five minutes for the availability checks,
     * in line with their current state, and a month of hour stats before it.
     */
    private function history(): void
    {
        $checks = Check::query()->where('enabled', true)->get()->filter(fn (Check $check): bool => $check->type->measuresAvailability());
        $start = $this->now->subDay();
        $rows = [];
        $stats = [];

        foreach ($checks as $check) {
            $failingSince = $check->state === CheckState::Up ? null : $check->last_state_changed_at;
            // An hour in the night with a few failures: a blip that never went down.
            $blip = $check->id % 4 === 0 ? $this->now->subHours(9)->startOfHour() : null;

            for ($at = $start; $at <= $this->now; $at = $at->addMinutes(5)) {
                $ok = ! ($failingSince !== null && $at >= $failingSince->subMinutes(5))
                    && ! ($blip !== null && $at >= $blip && $at < $blip->addMinutes(15));

                $rows[] = [
                    'check_id' => $check->id,
                    'checked_at' => $at,
                    'ok' => $ok,
                    // Busier in some hours than in others, and never quite the same twice.
                    'response_ms' => $ok ? $this->responseMs($check) + ($at->hour * 37) % 90 + $at->minute % 7 * 3 : null,
                    'status_code' => $check->type->isHttp() ? ($ok ? 200 : 502) : null,
                    'error' => $ok ? null : 'Demo: no response',
                ];
            }

            // A month of hours, for the status page: a few blips in the night,
            // one bad evening twelve days ago, and slower in the daytime.
            for ($hour = $start->startOfHour()->subDays(30); $hour < $start->startOfHour(); $hour = $hour->addHour()) {
                $down = match (true) {
                    $check->id % 3 === 0 && $hour->isSameDay($this->now->subDays(12)) && $hour->hour >= 18 && $hour->hour < 20 => 9,
                    $check->id % 5 === 0 && $hour->hour === 3 && $hour->day % 3 === 0 => 2,
                    default => 0,
                };

                $stats[] = [
                    'check_id' => $check->id,
                    'granularity' => CheckStat::HOUR,
                    'period_start' => $hour,
                    'up_count' => 12 - $down,
                    'down_count' => $down,
                    'avg_ms' => $this->responseMs($check) + (int) round(0.3 * $this->responseMs($check) * sin(($hour->hour - 8) / 24 * 2 * M_PI)) + ($hour->day * 23) % 50,
                    'min_ms' => 40,
                    'max_ms' => 900,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            CheckResult::insert($chunk);
        }

        foreach (array_chunk($stats, 500) as $chunk) {
            CheckStat::insert($chunk);
        }
    }

    private function project(string $name, ProjectType $type, ?Client $client = null, array $tags = [], ProjectStatus $status = ProjectStatus::Active, bool $onScreen = false, bool $unmonitored = false): Project
    {
        return Project::create([
            'name' => $name,
            'type' => $type,
            'status' => $status,
            'on_status_screen' => $onScreen,
            'unmonitored' => $unmonitored,
            'client_id' => $client?->id,
            'tags' => $tags,
            'description' => 'Demo data: a made-up project.',
        ]);
    }

    /**
     * @param  array<string, ServerRole>  $servers
     */
    private function environment(Project $project, EnvironmentKind $kind, array $servers): Environment
    {
        $environment = $project->environments()->create([
            'name' => $kind->getLabel(),
            'kind' => $kind,
            'branch' => $kind === EnvironmentKind::Production ? 'main' : 'develop',
            'deploy_method' => 'deploy.sh',
            'sort_order' => $kind === EnvironmentKind::Production ? 0 : 1,
        ]);

        foreach ($servers as $server => $role) {
            EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $this->servers[$server]->id, 'role' => $role]);
        }

        return $environment;
    }

    private function hostname(string $fqdn, Environment $environment, string $purpose, bool $behindProxy = false, ?string $redirectsTo = null): Hostname
    {
        $domain = collect($this->domains)->first(fn (Domain $domain): bool => Hostname::belongsToDomain($fqdn, $domain->name));

        return Hostname::create([
            'domain_id' => $domain->id,
            'environment_id' => $environment->id,
            'fqdn' => $fqdn,
            'purpose' => $purpose,
            'redirects_to' => $redirectsTo,
            'behind_proxy' => $behindProxy,
        ]);
    }

    /**
     * The default checks, all up unless $states says otherwise.
     *
     * @param  array<string, array{0: CheckState, 1: int}>  $states  check type => state, minutes since it changed
     */
    private function monitor(Hostname $hostname, array $states = []): void
    {
        app(DefaultChecks::class)->createFor($hostname);

        foreach ($hostname->checks()->get() as $check) {
            [$state, $minutes] = $states[$check->type->value] ?? [CheckState::Up, 60 * 24 * 3];

            $this->settle($check, $state, $minutes);
        }
    }

    /**
     * Sets the engine's columns (not fillable) as if it had been running,
     * and keeps the check from ever being scheduled.
     */
    private function settle(Check $check, CheckState $state, int $minutesAgo = 60 * 24 * 3): void
    {
        $check->forceFill([
            'state' => $state,
            'last_state_changed_at' => $this->now->subMinutes($minutesAgo),
            'last_checked_at' => $this->now->subSeconds(20),
            'last_response_ms' => $state === CheckState::Up ? $this->responseMs($check) : null,
            'last_error' => match ($state) {
                CheckState::Down => $check->type === CheckType::Tls ? 'Certificate expires in 9 days' : 'HTTP 502',
                CheckState::Pending => 'Timed out after 10 seconds',
                default => null,
            },
            'consecutive_failures' => $state === CheckState::Up ? 0 : 3,
            'consecutive_successes' => $state === CheckState::Up ? 100 : 0,
            'next_check_at' => $this->now->addYears(10),
        ])->saveQuietly();
    }

    private function incident(Project $project, string $title, IncidentSeverity $severity, IncidentStatus $status, int $minutesAgo): void
    {
        $started = $this->now->subMinutes($minutesAgo);

        $incident = Incident::create([
            'title' => $title,
            'severity' => $severity,
            'status' => $status,
            'occurred_on' => $started->timezone(config('herdbook.display_timezone'))->toDateString(),
            'started_at' => $started,
            'detected_at' => $started->addMinutes(2),
            'resolved_at' => $status === IncidentStatus::Resolved ? $started->addMinutes(47) : null,
            'impact' => 'Demo data: a made-up incident.',
            'source' => $status === IncidentStatus::Open ? IncidentSource::Check : IncidentSource::Manual,
        ]);

        $incident->projects()->attach($project);
    }

    private function dependsOn(string $server, string $dependsOn, string $kind): void
    {
        ServerDependency::create(['server_id' => $this->servers[$server]->id, 'depends_on_id' => $this->servers[$dependsOn]->id, 'kind' => $kind]);
    }

    private function responseMs(Check $check): int
    {
        return match ($check->type) {
            CheckType::Ping => 8 + ($check->id * 7) % 25,
            default => 90 + ($check->id * 37) % 260,
        };
    }

    private function today(): CarbonImmutable
    {
        return Date::now(config('herdbook.display_timezone'))->startOfDay();
    }
}
