<?php

namespace Tests\Feature\Filament;

use App\Enums\CheckState;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\ServerRole;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\RelationManagers\EnvironmentsRelationManager;
use App\Models\Check;
use App\Models\Client;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Incident;
use App\Models\Project;
use App\Models\Server;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class ProjectFlowTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_a_project_is_created_with_an_optional_client(): void
    {
        $this->signIn();
        $client = Client::factory()->create();

        Livewire::test(CreateProject::class)
            ->fillForm([
                'name' => 'Klantportaal',
                'slug' => 'klantportaal',
                'type' => ProjectType::ClientProject,
                'client_id' => $client->id,
                'tags' => ['Laravel', 'Filament'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::query()->where('slug', 'klantportaal')->sole();
        $this->assertTrue($project->client->is($client));
        $this->assertSame(['Laravel', 'Filament'], $project->tags);
    }

    public function test_a_project_goes_on_the_status_screen_with_a_switch(): void
    {
        $this->signIn();

        Livewire::test(CreateProject::class)
            ->fillForm([
                'name' => 'Wandbord',
                'slug' => 'wandbord',
                'type' => ProjectType::Product,
                'on_status_screen' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Project::query()->where('slug', 'wandbord')->sole()->on_status_screen);
    }

    public function test_a_project_that_needs_no_checks_says_so_with_a_switch(): void
    {
        $this->signIn();

        Livewire::test(CreateProject::class)
            ->fillForm([
                'name' => 'Herdbook',
                'slug' => 'herdbook',
                'type' => ProjectType::Internal,
                'unmonitored' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::query()->where('slug', 'herdbook')->sole();
        $this->assertTrue($project->unmonitored);
        $this->get(ProjectResource::getUrl('view', ['record' => $project]))->assertSee(['Bewaking', 'Niet nodig']);
    }

    public function test_a_private_key_in_the_description_is_refused(): void
    {
        $this->signIn();

        Livewire::test(CreateProject::class)
            ->fillForm([
                'name' => 'Lek',
                'slug' => 'lek',
                'type' => ProjectType::Internal,
                'description' => "-----BEGIN OPENSSH PRIVATE KEY-----\nabc",
            ])
            ->call('create')
            ->assertHasFormErrors(['description']);

        $this->assertSame(0, Project::count());
    }

    public function test_an_environment_is_created_with_the_servers_it_runs_on(): void
    {
        $this->signIn();
        $project = Project::factory()->create();
        [$app, $storage] = Server::factory()->count(2)->create();

        Livewire::test(EnvironmentsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
            ->callAction(TestAction::make('create')->table(), [
                'name' => 'Productie',
                'kind' => 'production',
                'serverAssignments' => [
                    ['server_id' => $app->id, 'role' => ServerRole::App->value, 'notes' => null],
                    ['server_id' => $storage->id, 'role' => ServerRole::Storage->value, 'notes' => null],
                ],
            ])
            ->assertHasNoFormErrors();

        $environment = $project->environments()->sole();
        $this->assertEqualsCanonicalizing(
            [[$app->id, 'app'], [$storage->id, 'storage']],
            $environment->serverAssignments->map(fn (EnvironmentServer $assignment): array => [$assignment->server_id, $assignment->role->value])->all(),
        );
    }

    public function test_the_same_server_in_the_same_role_twice_is_refused(): void
    {
        $this->signIn();
        $project = Project::factory()->create();
        $server = Server::factory()->create();

        Livewire::test(EnvironmentsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
            ->callAction(TestAction::make('create')->table(), [
                'name' => 'Productie',
                'kind' => 'production',
                'serverAssignments' => [
                    ['server_id' => $server->id, 'role' => 'app', 'notes' => null],
                    ['server_id' => $server->id, 'role' => 'app', 'notes' => null],
                ],
            ])
            ->assertHasFormErrors(['serverAssignments']);

        $this->assertSame(0, $project->environments()->count());
    }

    public function test_two_environments_of_one_project_cannot_share_a_name(): void
    {
        $this->signIn();
        $project = Project::factory()->create();
        $project->environments()->create(['name' => 'Productie', 'kind' => 'production']);

        Livewire::test(EnvironmentsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
            ->callAction(TestAction::make('create')->table(), ['name' => 'Productie', 'kind' => 'production'])
            ->assertHasFormErrors(['name']);
    }

    public function test_the_project_list_shows_what_needs_attention(): void
    {
        $this->signIn();
        $environment = Environment::factory()->create();
        $check = Check::factory()->create(['hostname_id' => Hostname::factory()->create(['environment_id' => $environment->id])->id]);
        $check->forceFill(['state' => CheckState::Down])->save();
        $environment->project->incidents()->attach(Incident::factory()->create());

        Livewire::test(ListProjects::class)
            ->assertTableColumnStateSet('attention', ['Storing · Production', '1 open incident'], $environment->project);
    }

    public function test_the_attention_column_counts_server_checks_too(): void
    {
        $this->signIn();
        $environment = Environment::factory()->create();
        $server = Server::factory()->create();
        EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $server->id, 'role' => ServerRole::Storage]);
        $ping = Check::factory()->forServer($server)->create();
        $ping->forceFill(['state' => CheckState::Down])->save();

        Livewire::test(ListProjects::class)
            ->assertTableColumnStateSet('attention', ['Storing · Production'], $environment->project);
    }

    public function test_taking_a_project_out_of_the_archive_says_its_checks_wait(): void
    {
        $this->signIn();
        $environment = Environment::factory()->create();
        Check::factory()->create(['hostname_id' => Hostname::factory()->create(['environment_id' => $environment->id])->id]);
        $environment->project->update(['status' => ProjectStatus::Archived]);

        Livewire::test(EditProject::class, ['record' => $environment->project->getRouteKey()])
            ->fillForm(['status' => ProjectStatus::Active])
            ->call('save')
            ->assertNotified('1 check staat nog op pauze');
    }

    public function test_swapping_the_roles_of_one_server_saves_without_error(): void
    {
        $this->signIn();
        $environment = Environment::factory()->create();
        $server = Server::factory()->create();
        $app = EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $server->id, 'role' => ServerRole::App]);
        $database = EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $server->id, 'role' => ServerRole::Database]);

        Livewire::test(EnvironmentsRelationManager::class, ['ownerRecord' => $environment->project, 'pageClass' => ViewProject::class])
            ->callAction(TestAction::make('edit')->table($environment), [
                'serverAssignments' => [
                    "record-{$app->id}" => ['server_id' => $server->id, 'role' => 'database', 'notes' => null],
                    "record-{$database->id}" => ['server_id' => $server->id, 'role' => 'app', 'notes' => null],
                ],
            ])
            ->assertHasNoFormErrors();

        $this->assertSame('database', $app->fresh()->role->value);
        $this->assertSame('app', $database->fresh()->role->value);
    }
}
