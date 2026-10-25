<?php

namespace Tests\Feature\Console;

use App\Enums\HealthState;
use App\Models\Check;
use App\Models\CheckStat;
use App\Models\Domain;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Costs\CostOverview;
use App\Services\Costs\RecurringCosts;
use App\Services\Health\ProjectHealth;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fills_an_empty_inventory_with_every_state_and_schedules_nothing(): void
    {
        $this->seed(DemoSeeder::class);

        $states = Project::query()->where('status', '!=', 'archived')->get()
            ->map(fn (Project $project): HealthState => app(ProjectHealth::class)->forProject($project)->state)
            ->unique();

        // All but "no measurement yet": that lasts a minute in real life.
        foreach (array_filter(HealthState::cases(), fn (HealthState $state): bool => $state !== HealthState::Unknown) as $state) {
            $this->assertContains($state, $states, "No demo project is {$state->value}");
        }

        $this->assertFalse(Check::query()->where('enabled', true)->where(fn ($query) => $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()->addYear()))->exists());
        $this->assertTrue(CheckStat::query()->where('period_start', '>=', now()->subDay())->exists());
    }

    public function test_it_has_costs_to_design_the_cost_page_on(): void
    {
        $this->seed(DemoSeeder::class);

        $overview = app(RecurringCosts::class)->overview();

        $this->assertTrue(Subscription::query()->exists());
        $this->assertSame(1, $overview->yearly->compare(0));
        $this->assertSame(1, CostOverview::decide($overview->months)->compare(0), 'Something to decide on.');
        $this->assertSame(1, $overview->missing()[Domain::class], 'A domain without an amount.');
        $this->assertNotEmpty($overview->unused);
    }

    public function test_it_refuses_an_inventory_with_data_in_it(): void
    {
        Provider::factory()->create();

        $this->expectException(RuntimeException::class);

        $this->seed(DemoSeeder::class);
    }

    public function test_it_refuses_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);

        // Directly: db:seed itself asks for confirmation in production.
        app(DemoSeeder::class)->run();
    }

    public function test_demo_reset_replaces_the_inventory_and_keeps_the_accounts(): void
    {
        $user = User::factory()->create();
        Project::factory()->create(['name' => 'Echt project']);

        $this->artisan('demo:reset', ['--force' => true])->assertSuccessful();

        $this->assertModelExists($user);
        $this->assertFalse(Project::query()->where('name', 'Echt project')->exists());
        $this->assertTrue(Project::query()->where('name', 'Studbook')->exists());
    }

    public function test_demo_reset_refuses_outside_local(): void
    {
        $this->app['env'] = 'production';
        Project::factory()->create();

        $this->artisan('demo:reset', ['--force' => true])->assertFailed();

        $this->assertSame(1, Project::query()->count());
    }

    public function test_the_default_seeder_creates_no_login(): void
    {
        $this->seed();

        $this->assertSame(0, User::count());
    }
}
