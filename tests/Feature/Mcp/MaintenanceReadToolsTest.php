<?php

namespace Tests\Feature\Mcp;

use App\Enums\AgentAccess;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceTask;
use App\Mcp\Serializer;
use App\Models\Maintenance;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsAgent;
use Tests\TestCase;

class MaintenanceReadToolsTest extends TestCase
{
    use ActsAsAgent, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-09 12:00:00');
        config(['herdbook.mcp.enabled' => true]);
        [, $this->token] = $this->agentToken();
    }

    public function test_an_item_has_exactly_these_fields_with_times_in_the_display_timezone(): void
    {
        $server = Server::factory()->create(['name' => 'app-1']);
        $project = Project::factory()->create(['name' => 'Shop']);
        $maintenance = Maintenance::factory()->planned()->for(User::factory()->state(['name' => 'Ada']))->create([
            'title' => 'Upgrade to PHP 8.5',
            'tasks' => [MaintenanceTask::Upgrade->value, MaintenanceTask::Reboot->value],
            'starts_at' => '2026-11-12 21:00:00',
            'ends_at' => '2026-11-12 23:00:00',
            'plan' => 'the-plan',
        ]);
        $maintenance->servers()->attach($server);
        $maintenance->projects()->attach($project);

        $found = $this->callToolSuccessfully($this->token, 'list_maintenance');

        $this->assertSame([[
            'id' => $maintenance->id,
            'title' => 'Upgrade to PHP 8.5',
            'tasks' => ['upgrade', 'reboot'],
            'status' => 'planned',
            'overdue' => false,
            'starts_at' => '2026-11-12T22:00:00+01:00',
            'ends_at' => '2026-11-13T00:00:00+01:00',
            'servers' => [['id' => $server->id, 'name' => 'app-1']],
            'projects' => [['id' => $project->id, 'name' => 'Shop']],
            'created_by' => 'Ada',
            'via' => null,
            'url' => rtrim(config('app.url'), '/')."/maintenances/{$maintenance->id}",
        ]], $found['maintenance']);
        $this->assertStringNotContainsString('the-plan', json_encode($found));
    }

    public function test_planned_includes_overdue_work_and_comes_in_the_order_it_is_due(): void
    {
        $nextWeek = Maintenance::factory()->planned()->create(['starts_at' => '2026-11-16 09:00:00']);
        $overdue = Maintenance::factory()->planned()->create(['starts_at' => '2026-11-02 09:00:00']);
        $tomorrow = Maintenance::factory()->planned()->create(['starts_at' => '2026-11-10 09:00:00']);
        $done = Maintenance::factory()->create(['starts_at' => '2026-11-05 09:00:00']);
        $cancelled = Maintenance::factory()->planned()->create(['status' => MaintenanceStatus::Cancelled, 'starts_at' => '2026-11-20 09:00:00']);

        $this->assertSame([$overdue->id, $tomorrow->id, $nextWeek->id], $this->ids(['status' => 'planned']));
        $this->assertSame([$overdue->id], $this->ids(['status' => 'overdue']));
        $this->assertSame([$done->id], $this->ids(['status' => 'done']));
        $this->assertSame([$cancelled->id], $this->ids(['status' => 'cancelled']));
        // Everything, newest first.
        $this->assertSame([$cancelled->id, $nextWeek->id, $tomorrow->id, $done->id, $overdue->id], $this->ids());
        $this->assertTrue($this->callToolSuccessfully($this->token, 'list_maintenance', ['status' => 'overdue'])['maintenance'][0]['overdue']);
    }

    public function test_servers_and_projects_find_the_maintenance_linked_to_any_of_them_directly(): void
    {
        [$app, $db] = Server::factory()->count(2)->create();
        $project = Project::factory()->create();
        $onApp = Maintenance::factory()->create(['starts_at' => '2026-11-08 09:00:00']);
        $onApp->servers()->attach($app);
        $onDb = Maintenance::factory()->create(['starts_at' => '2026-11-07 09:00:00']);
        $onDb->servers()->attach($db);
        $onProject = Maintenance::factory()->create(['starts_at' => '2026-11-06 09:00:00']);
        $onProject->projects()->attach($project);
        Maintenance::factory()->create();

        $this->assertSame([$onApp->id], $this->ids(['server_ids' => [$app->id]]));
        $this->assertSame([$onApp->id, $onDb->id], $this->ids(['server_ids' => [$app->id, $db->id]]));
        $this->assertSame([$onProject->id], $this->ids(['project_ids' => [$project->id]]));
        $this->assertSame([$onApp->id, $onProject->id], $this->ids(['server_ids' => [$app->id], 'project_ids' => [$project->id]]));
    }

    public function test_an_unknown_server_or_project_points_to_search_inventory(): void
    {
        $server = Server::factory()->create();

        $this->assertSame('Geen server heeft id 998, 999. search_inventory vindt de id\'s.', $this->callToolError($this->token, 'list_maintenance', ['server_ids' => [$server->id, 998, 999]]));
        $this->assertSame('Geen project heeft id 997. search_inventory vindt de id\'s.', $this->callToolError($this->token, 'list_maintenance', ['project_ids' => [997]]));
    }

    public function test_blank_arguments_count_as_left_out(): void
    {
        Maintenance::factory()->count(2)->create();

        foreach ([['status' => ' '], ['status' => ''], ['limit' => ''], ['task' => ' ']] as $arguments) {
            $this->assertCount(2, $this->ids($arguments), json_encode($arguments));
        }
    }

    public function test_task_and_limit(): void
    {
        $reboot = Maintenance::factory()->create(['tasks' => [MaintenanceTask::Updates->value, MaintenanceTask::Reboot->value], 'starts_at' => '2026-11-08 09:00:00']);
        Maintenance::factory()->create(['tasks' => [MaintenanceTask::Updates->value], 'starts_at' => '2026-11-07 09:00:00']);
        Maintenance::factory()->create(['tasks' => [MaintenanceTask::Updates->value], 'starts_at' => '2026-11-06 09:00:00']);

        $this->assertSame([$reboot->id], $this->ids(['task' => 'reboot']));

        $limited = $this->callToolSuccessfully($this->token, 'list_maintenance', ['limit' => 2]);
        $this->assertCount(2, $limited['maintenance']);
        $this->assertTrue($limited['more']);
        $this->assertFalse($this->callToolSuccessfully($this->token, 'list_maintenance', ['limit' => 3])['more']);
    }

    public function test_a_huge_list_of_ids_is_refused_before_any_is_looked_up(): void
    {
        DB::enableQueryLog();
        $this->callToolError($this->token, 'list_maintenance', ['server_ids' => range(1, 5000)]);

        $this->assertLessThan(10, count(DB::getQueryLog()));
    }

    public function test_twenty_items_unless_asked_otherwise(): void
    {
        Maintenance::factory()->count(20)->create();
        $this->assertFalse($this->callToolSuccessfully($this->token, 'list_maintenance')['more']);

        Maintenance::factory()->create();
        $found = $this->callToolSuccessfully($this->token, 'list_maintenance');

        $this->assertCount(20, $found['maintenance']);
        $this->assertTrue($found['more']);
    }

    public function test_wrong_input_is_refused_in_words_the_agent_can_act_on(): void
    {
        foreach ([['status' => 'soon'], ['task' => 'dusting'], ['limit' => 51], ['server_ids' => ['app-1']], ['server_ids' => ['']], ['server_ids' => ['a' => 1]], ['server_ids' => range(1, 51)]] as $arguments) {
            $this->callToolError($this->token, 'list_maintenance', $arguments);
        }

        $this->assertStringContainsString('server_ids', $this->callToolError($this->token, 'list_maintenance', ['server_ids' => 'app-1']));
    }

    public function test_get_maintenance_adds_its_plan_and_report(): void
    {
        $maintenance = Maintenance::factory()->create(['plan' => '1. Drain the queue', 'report' => 'All went well.']);

        $found = $this->callToolSuccessfully($this->token, 'get_maintenance', ['id' => $maintenance->id]);

        $this->assertSame([...array_keys(Serializer::maintenance($maintenance)), 'plan', 'report', 'truncated'], array_keys($found));
        $this->assertSame(['1. Drain the queue', 'All went well.', []], [$found['plan'], $found['report'], $found['truncated']]);
    }

    public function test_a_long_plan_is_cut_and_says_so(): void
    {
        $maintenance = Maintenance::factory()->create(['plan' => str_repeat('é', Serializer::MAX_TEXT + 1), 'report' => null]);

        $found = $this->callToolSuccessfully($this->token, 'get_maintenance', ['id' => $maintenance->id]);

        $this->assertSame(Serializer::MAX_TEXT, mb_strlen($found['plan']));
        $this->assertNull($found['report']);
        $this->assertSame(['plan'], $found['truncated']);
    }

    public function test_get_maintenance_says_so_when_there_is_none(): void
    {
        $this->assertSame('Er is geen onderhoud met id 999. list_maintenance vindt ze.', $this->callToolError($this->token, 'get_maintenance', ['id' => 999]));
        $this->callToolError($this->token, 'get_maintenance');
    }

    public function test_listing_many_loads_their_links_up_front(): void
    {
        // Strict mode turns a lazy load into an error once a query returns more than one model.
        foreach (range(1, 3) as $index) {
            $maintenance = Maintenance::factory()->for(User::factory())->create();
            $maintenance->servers()->attach(Server::factory()->create());
            $maintenance->projects()->attach(Project::factory()->create());
        }

        $this->assertCount(3, $this->ids());
    }

    public function test_every_token_may_read_and_the_tools_say_they_only_read(): void
    {
        foreach (AgentAccess::cases() as $access) {
            [, $token] = $this->agentToken(access: $access);
            $tools = collect($this->mcp($token, 'tools/list')->json('result.tools'))->keyBy('name');

            foreach (['search_inventory', 'list_maintenance', 'get_maintenance'] as $name) {
                $this->assertSame(['readOnlyHint' => true, 'openWorldHint' => false], array_intersect_key($tools[$name]['annotations'], ['readOnlyHint' => 0, 'openWorldHint' => 0]), "{$name}, {$access->value}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<int>
     */
    private function ids(array $arguments = []): array
    {
        return array_column($this->callToolSuccessfully($this->token, 'list_maintenance', $arguments)['maintenance'], 'id');
    }
}
