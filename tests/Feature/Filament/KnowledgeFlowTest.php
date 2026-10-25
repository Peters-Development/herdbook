<?php

namespace Tests\Feature\Filament;

use App\Enums\CredentialKind;
use App\Enums\DocumentKind;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Filament\Resources\CredentialReferences\Pages\CreateCredentialReference;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\Documents\RelationManagers\RevisionsRelationManager;
use App\Filament\Resources\Incidents\Pages\CreateIncident;
use App\Filament\Resources\Incidents\Pages\ViewIncident;
use App\Filament\Resources\Incidents\RelationManagers\ActionsRelationManager;
use App\Filament\Resources\Incidents\RelationManagers\TimelineEntriesRelationManager;
use App\Filament\Support\LinkSchema;
use App\Models\CredentialReference;
use App\Models\Document;
use App\Models\Incident;
use App\Models\Project;
use App\Models\Server;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class KnowledgeFlowTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const string ITEM_ID = '0f8e2a6c-3b1d-4c5e-9a7f-1d2c3b4a5e6f';

    public function test_a_credential_keeps_only_the_item_id_of_a_pasted_vault_link(): void
    {
        $this->signIn();
        $server = Server::factory()->create();

        Livewire::test(CreateCredentialReference::class)
            ->fillForm([
                'label' => 'SSH deploy',
                'kind' => CredentialKind::SshKey,
                'username' => 'deploy',
                'vault_item_id' => 'https://vault.example.ts.net/#/vault?itemId='.self::ITEM_ID,
                'servers' => [$server->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $credential = CredentialReference::query()->sole();
        $this->assertSame(self::ITEM_ID, $credential->vault_item_id);
        $this->assertTrue($credential->servers()->sole()->is($server));
        $this->assertTrue($server->credentialReferences()->sole()->is($credential));
    }

    public function test_a_credential_refuses_something_that_is_not_a_vault_item(): void
    {
        $this->signIn();

        Livewire::test(CreateCredentialReference::class)
            ->fillForm(['label' => 'Panel', 'kind' => CredentialKind::PanelLogin, 'vault_item_id' => 'hunter2'])
            ->call('create')
            ->assertHasFormErrors(['vault_item_id']);
    }

    public function test_a_credential_refuses_a_private_key_in_its_notes(): void
    {
        $this->signIn();

        Livewire::test(CreateCredentialReference::class)
            ->fillForm(['label' => 'SSH', 'kind' => CredentialKind::SshKey, 'notes' => '-----BEGIN RSA PRIVATE KEY-----'])
            ->call('create')
            ->assertHasFormErrors(['notes']);
    }

    public function test_editing_a_document_keeps_a_revision_that_can_be_restored(): void
    {
        $this->signIn();
        $document = Document::factory()->create(['body' => 'versie 1', 'kind' => DocumentKind::Runbook]);

        Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])
            ->fillForm(['body' => 'versie 2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $revision = $document->revisions()->sole();

        Livewire::test(RevisionsRelationManager::class, ['ownerRecord' => $document->fresh(), 'pageClass' => ViewDocument::class])
            ->callAction(TestAction::make('restore')->table($revision));

        $this->assertSame('versie 1', $document->fresh()->body);
        $this->assertSame(2, $document->revisions()->count());
    }

    public function test_a_document_renders_markdown_without_running_scripts(): void
    {
        $this->signIn();
        $document = Document::factory()->create(['body' => "## Herstel\n\n<script>alert('x')</script>"]);

        $this->get(DocumentResource::getUrl('view', ['record' => $document]))
            ->assertOk()
            ->assertSee('Herstel')
            ->assertDontSee("<script>alert('x')</script>", false);
    }

    public function test_an_incident_is_created_with_its_links(): void
    {
        $this->signIn();
        $project = Project::factory()->create();
        $server = Server::factory()->create();

        Livewire::test(CreateIncident::class)
            ->fillForm([
                'title' => 'App-stack kwam niet terug',
                'severity' => IncidentSeverity::Sev1,
                'status' => IncidentStatus::Open,
                'occurred_on' => '2026-07-27',
                'started_at' => '2026-07-27 13:04',
                'detected_at' => '2026-07-27 14:10',
                'projects' => [$project->id],
                'servers' => [$server->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $incident = Incident::query()->sole();
        $this->assertTrue($project->incidents()->sole()->is($incident));
        $this->assertTrue($server->incidents()->sole()->is($incident));
        $this->assertSame(66, (int) $incident->timeToDetect()->totalMinutes);
    }

    public function test_detection_cannot_come_before_the_start(): void
    {
        $this->signIn();

        Livewire::test(CreateIncident::class)
            ->fillForm([
                'title' => 'Tijdreis',
                'severity' => IncidentSeverity::Sev3,
                'status' => IncidentStatus::Open,
                'occurred_on' => '2026-07-27',
                'started_at' => '2026-07-27 14:00',
                'detected_at' => '2026-07-27 13:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['detected_at']);
    }

    public function test_a_timeline_entry_records_who_wrote_it(): void
    {
        $user = $this->signIn();
        $incident = Incident::factory()->create();

        Livewire::test(TimelineEntriesRelationManager::class, ['ownerRecord' => $incident, 'pageClass' => ViewIncident::class])
            ->callAction(TestAction::make('create')->table(), ['occurred_at' => '2026-07-27 13:15', 'note' => 'Containers stonden op Exited'])
            ->assertHasNoFormErrors();

        $this->assertTrue($incident->timelineEntries()->sole()->user->is($user));
    }

    public function test_an_action_is_completed_and_reopened(): void
    {
        $this->signIn();
        $incident = Incident::factory()->create();
        $action = $incident->actions()->create(['description' => 'NFS-latency monitoren']);
        $parameters = ['ownerRecord' => $incident, 'pageClass' => ViewIncident::class];

        Livewire::test(ActionsRelationManager::class, $parameters)->callAction(TestAction::make('complete')->table($action));
        $this->assertTrue($action->fresh()->isDone());

        Livewire::test(ActionsRelationManager::class, $parameters)->callAction(TestAction::make('reopen')->table($action));
        $this->assertFalse($action->fresh()->isDone());
    }

    public function test_restoring_a_revision_reloads_the_document(): void
    {
        $this->signIn();
        $document = Document::factory()->create(['body' => 'versie 1']);
        $document->update(['body' => 'versie 2']);

        Livewire::test(RevisionsRelationManager::class, ['ownerRecord' => $document->fresh(), 'pageClass' => ViewDocument::class])
            ->callAction(TestAction::make('restore')->table($document->revisions()->sole()))
            ->assertRedirect(DocumentResource::getUrl('view', ['record' => $document]));
    }

    public function test_creating_from_a_project_page_preselects_the_project(): void
    {
        $this->signIn();
        $project = Project::factory()->create();

        Livewire::withQueryParams([LinkSchema::QUERY_PARAMETER => LinkSchema::linkKey($project)])
            ->test(CreateIncident::class)
            ->assertSchemaStateSet(['projects' => [$project->id], 'servers' => []])
            ->fillForm(['title' => 'Outage', 'severity' => 'sev2', 'status' => 'open', 'occurred_on' => '2026-10-22'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue($project->incidents()->sole()->is(Incident::query()->sole()));
    }

    public function test_a_link_to_something_that_does_not_exist_preselects_nothing(): void
    {
        $this->signIn();

        Livewire::withQueryParams([LinkSchema::QUERY_PARAMETER => 'project:999'])
            ->test(CreateIncident::class)
            ->assertSchemaStateSet(['projects' => []]);
    }

    public function test_an_overly_long_timeline_note_is_refused_instead_of_failing_in_mysql(): void
    {
        $this->signIn();

        Livewire::test(TimelineEntriesRelationManager::class, ['ownerRecord' => Incident::factory()->create(), 'pageClass' => ViewIncident::class])
            ->callAction(TestAction::make('create')->table(), ['occurred_at' => '2026-10-22 10:00', 'note' => Str::repeat('x', 10001)])
            ->assertHasFormErrors(['note']);
    }

    public function test_documents_are_found_by_their_content(): void
    {
        $this->signIn();
        $runbook = Document::factory()->create(['title' => 'Herstel', 'body' => 'Draai acme-stack.service opnieuw']);
        $other = Document::factory()->create(['title' => 'Ander', 'body' => 'Niets']);

        Livewire::test(ListDocuments::class)
            ->searchTable('acme-stack')
            ->assertCanSeeTableRecords([$runbook])
            ->assertCanNotSeeTableRecords([$other]);
    }
}
