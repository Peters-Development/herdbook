<?php

namespace Tests\Feature\Inventory;

use App\Enums\EnvironmentKind;
use App\Enums\ServerRole;
use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Support\ActivityPresenter;
use App\Models\Check;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerDependency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class ActivityPresenterTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_changes_read_with_labels_dates_timestamps_and_enum_labels(): void
    {
        $domain = Domain::factory()->create(['expires_on' => '2027-04-22', 'auto_renews' => true]);
        $domain->update(['expires_on' => '2028-04-22', 'auto_renews' => false, 'renewal_stopped_at' => '2026-10-22 04:10:00']);

        $changes = ActivityPresenter::changes(Activity::query()->where('event', 'updated')->sole());

        $this->assertStringContainsString('Verloopdatum: 22-04-2027 → 22-04-2028', $changes);
        $this->assertStringContainsString('Verlengt automatisch: ja → nee', $changes);
        // stored in UTC, shown in Amsterdam (summer time: +2)
        $this->assertStringContainsString('Opgezegd: — → 22-10-2026 06:10', $changes);
    }

    public function test_an_enum_change_shows_its_label(): void
    {
        $environment = Environment::factory()->create(['kind' => EnvironmentKind::Staging]);
        $environment->update(['kind' => EnvironmentKind::Production]);

        $changes = ActivityPresenter::changes(Activity::query()->where('event', 'updated')->sole());

        $this->assertSame('Soort: Staging → Productie', $changes);
    }

    public function test_events_read_in_dutch_and_a_renewal_keeps_its_own_text(): void
    {
        $domain = Domain::factory()->create(['expires_on' => '2027-04-22']);
        $domain->renew();

        $descriptions = Activity::query()->orderBy('id')->get()->map(fn (Activity $activity): string => ActivityPresenter::description($activity))->all();

        $this->assertSame(['Aangemaakt', 'Verlengd tot 22-04-2028'], $descriptions);
    }

    public function test_changes_follow_the_language_of_the_reader(): void
    {
        $domain = Domain::factory()->create(['expires_on' => '2027-04-22', 'auto_renews' => true]);
        $domain->update(['expires_on' => '2028-04-22', 'auto_renews' => false]);
        $activity = Activity::query()->where('event', 'updated')->sole();

        app()->setLocale('en');

        $this->assertSame('Changed', ActivityPresenter::description($activity));
        $this->assertStringContainsString('Expiry date: 22 Apr 2027 → 22 Apr 2028', ActivityPresenter::changes($activity));
        $this->assertStringContainsString('Renews automatically: yes → no', ActivityPresenter::changes($activity));
    }

    public function test_a_renewal_is_stored_as_a_key_and_reads_in_the_language_of_the_reader(): void
    {
        $domain = Domain::factory()->create();
        Activity::query()->delete();

        activity()->performedOn($domain)->event('renewed')->withProperties(['date' => '2028-04-22'])->log('Renewed until :date');
        $activity = Activity::query()->sole();

        $this->assertSame('Verlengd tot 22-04-2028', ActivityPresenter::description($activity));

        app()->setLocale('en');

        $this->assertSame('Renewed until 22 Apr 2028', ActivityPresenter::description($activity));
    }

    public function test_any_other_description_is_shown_as_stored(): void
    {
        $domain = Domain::factory()->create();
        Activity::query()->delete();

        activity()->performedOn($domain)->event('noted')->log('Moved to another rack');

        app()->setLocale('en');

        $this->assertSame('Moved to another rack', ActivityPresenter::description(Activity::query()->sole()));
    }

    public function test_references_show_names_and_enum_lists_their_labels(): void
    {
        $old = Provider::factory()->create(['name' => 'Oude BV']);
        $new = Provider::factory()->create(['name' => 'Nieuwe BV', 'kinds' => ['hosting']]);
        $server = Server::factory()->create(['provider_id' => $old->id]);
        Activity::query()->delete();

        $server->update(['provider_id' => $new->id]);
        $new->update(['kinds' => ['hosting', 'dns']]);

        $this->assertSame('Leverancier: Oude BV → Nieuwe BV', ActivityPresenter::changes(Activity::query()->where('subject_type', 'server')->sole()));
        $this->assertSame('Levert: Hosting → Hosting, DNS', ActivityPresenter::changes(Activity::query()->where('subject_type', 'provider')->sole()));
    }

    public function test_check_changes_read_in_dutch_in_the_activity_log(): void
    {
        $check = Check::factory()->create(['interval_seconds' => 60]);
        $before = $check->hostname->fqdn;
        $other = Hostname::factory()->create();

        $check->update(['enabled' => false, 'interval_seconds' => 300, 'hostname_id' => $other->id]);

        $changes = ActivityPresenter::changes(Activity::query()->where('subject_type', 'check')->where('event', 'updated')->sole());
        $this->assertStringContainsString('Actief: ja → nee', $changes);
        $this->assertStringContainsString('Hoe vaak (seconden): 60 → 300', $changes);
        $this->assertStringContainsString("Hostname: {$before} → {$other->fqdn}", $changes);
    }

    public function test_the_project_activity_shows_its_environments_and_servers_even_after_a_delete(): void
    {
        $this->signIn();
        $project = Project::factory()->create();
        $environment = $project->environments()->create(['name' => 'Productie', 'kind' => 'production']);
        $server = Server::factory()->create(['name' => 'storage-1']);
        $assignment = EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $server->id, 'role' => ServerRole::Storage]);
        $assignment->delete();
        $unrelated = Environment::factory()->create(['name' => 'Elders']);

        Livewire::test(ActivityRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
            ->assertSee('Aangemaakt · Omgeving Productie')
            ->assertSee('Verwijderd · Server-koppeling storage-1 (Opslag) in Productie')
            ->assertDontSee('Omgeving Elders');
    }

    public function test_the_server_activity_shows_its_dependencies(): void
    {
        $this->signIn();
        $app = Server::factory()->create(['name' => 'app-1']);
        $storage = Server::factory()->create(['name' => 'storage-1']);
        ServerDependency::create(['server_id' => $app->id, 'depends_on_id' => $storage->id, 'kind' => 'NFS']);

        foreach ([$app, $storage] as $server) {
            Livewire::test(ActivityRelationManager::class, ['ownerRecord' => $server, 'pageClass' => ViewServer::class])
                ->assertSee('Aangemaakt · Afhankelijkheid app-1 → NFS → storage-1');
        }
    }
}
