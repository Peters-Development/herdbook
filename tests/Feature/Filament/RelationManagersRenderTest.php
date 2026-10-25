<?php

namespace Tests\Feature\Filament;

use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\RelationManagers\CredentialReferencesRelationManager;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\RelationManagers\IncidentsRelationManager;
use App\Filament\RelationManagers\MaintenancesRelationManager;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\Documents\RelationManagers\RevisionsRelationManager;
use App\Filament\Resources\Domains\Pages\ViewDomain;
use App\Filament\Resources\Domains\RelationManagers\HostnamesRelationManager as DomainHostnames;
use App\Filament\Resources\Incidents\Pages\ViewIncident;
use App\Filament\Resources\Incidents\RelationManagers\ActionsRelationManager;
use App\Filament\Resources\Incidents\RelationManagers\TimelineEntriesRelationManager;
use App\Filament\Resources\Maintenances\Pages\ViewMaintenance;
use App\Filament\Resources\Networks\Pages\ViewNetwork;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\EnvironmentsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\HostnamesRelationManager as ProjectHostnames;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Models\CredentialReference;
use App\Models\Document;
use App\Models\Domain;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class RelationManagersRenderTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    /** @return array<string, array{class-string, class-string<Model>, class-string}> */
    public static function relationManagers(): array
    {
        return [
            'project environments' => [EnvironmentsRelationManager::class, Project::class, ViewProject::class],
            'project hostnames' => [ProjectHostnames::class, Project::class, ViewProject::class],
            'project incidents' => [IncidentsRelationManager::class, Project::class, ViewProject::class],
            'project documents' => [DocumentsRelationManager::class, Project::class, ViewProject::class],
            'project credentials' => [CredentialReferencesRelationManager::class, Project::class, ViewProject::class],
            'project activity' => [ActivityRelationManager::class, Project::class, ViewProject::class],
            'domain hostnames' => [DomainHostnames::class, Domain::class, ViewDomain::class],
            'document revisions' => [RevisionsRelationManager::class, Document::class, ViewDocument::class],
            'incident timeline' => [TimelineEntriesRelationManager::class, Incident::class, ViewIncident::class],
            'incident actions' => [ActionsRelationManager::class, Incident::class, ViewIncident::class],
            'project maintenance' => [MaintenancesRelationManager::class, Project::class, ViewProject::class],
            'server maintenance' => [MaintenancesRelationManager::class, Server::class, ViewServer::class],
            'maintenance activity' => [ActivityRelationManager::class, Maintenance::class, ViewMaintenance::class],
            'network incidents' => [IncidentsRelationManager::class, Network::class, ViewNetwork::class],
            'network documents' => [DocumentsRelationManager::class, Network::class, ViewNetwork::class],
            'network credentials' => [CredentialReferencesRelationManager::class, Network::class, ViewNetwork::class],
            'network activity' => [ActivityRelationManager::class, Network::class, ViewNetwork::class],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('relationManagers')]
    public function test_the_relation_manager_renders(string $relationManager, string $model, string $page): void
    {
        $this->signIn();
        $owner = $model::factory()->create();
        $this->giveRows($owner);

        Livewire::test($relationManager, ['ownerRecord' => $owner, 'pageClass' => $page])
            ->assertOk();
    }

    /**
     * Rows for every relation manager, so their column closures run.
     */
    private function giveRows(Model $owner): void
    {
        match (true) {
            $owner instanceof Project => (function () use ($owner): void {
                foreach (range(1, 2) as $i) {
                    $environment = $owner->environments()->create(['name' => "Environment {$i}", 'kind' => 'production']);
                    EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => Server::factory()->create()->id, 'role' => 'app']);
                    Hostname::factory()->create(['environment_id' => $environment->id]);
                    $owner->incidents()->attach(Incident::factory()->create());
                    $owner->documents()->attach(Document::factory()->create());
                    $owner->credentialReferences()->attach(CredentialReference::factory()->create());
                    $owner->maintenances()->attach(Maintenance::factory()->create());
                }
                $owner->update(['description' => 'gewijzigd']);
            })(),
            $owner instanceof Domain => Hostname::factory()->count(2)->create(['domain_id' => $owner->id]),
            $owner instanceof Network => (function () use ($owner): void {
                foreach (range(1, 2) as $i) {
                    $owner->addresses()->create(['address' => "192.168.1.{$i}", 'name' => "Apparaat {$i}"]);
                    $owner->incidents()->attach(Incident::factory()->create());
                    $owner->documents()->attach(Document::factory()->create());
                    $owner->credentialReferences()->attach(CredentialReference::factory()->create());
                }
                $owner->update(['gateway' => '192.168.1.254']);
            })(),
            $owner instanceof Server => $owner->maintenances()->attach([Maintenance::factory()->create()->id, Maintenance::factory()->planned()->create()->id]),
            $owner instanceof Maintenance => (function () use ($owner): void {
                $owner->update(['status' => 'planned']);
                $owner->update(['tasks' => ['updates', 'reboot'], 'status' => 'done']);
            })(),
            $owner instanceof Document => (function () use ($owner): void {
                $owner->update(['body' => 'versie 2']);
                $owner->update(['body' => 'versie 3']);
            })(),
            $owner instanceof Incident => (function () use ($owner): void {
                $owner->timelineEntries()->create(['occurred_at' => now(), 'note' => 'Eerste']);
                $owner->timelineEntries()->create(['occurred_at' => now(), 'note' => 'Tweede']);
                $owner->actions()->create(['description' => 'Eén']);
                $owner->actions()->create(['description' => 'Twee']);
            })(),
            default => null,
        };
    }
}
