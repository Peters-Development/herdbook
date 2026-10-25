<?php

namespace Tests\Feature\Console;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportIncidentsTest extends TestCase
{
    use RefreshDatabase;

    private function directory(): string
    {
        return base_path('tests/Fixtures/incidents');
    }

    public function test_it_imports_dated_reports_with_title_date_impact_and_body(): void
    {
        $this->artisan('incidents:import', ['directory' => $this->directory(), '--source' => 'acme'])
            ->assertSuccessful();

        $this->assertSame(2, Incident::count());

        $incident = Incident::query()->where('imported_from', 'acme/2026-07-27-app-stack-not-recovered.md')->sole();
        $this->assertSame('App stack did not come back after a reboot', $incident->title);
        $this->assertSame('2026-07-27', $incident->occurred_on->toDateString());
        $this->assertSame('Every site on `app-1` returned `Bad Gateway`. About 40 minutes, mostly unnoticed.', $incident->impact);
        $this->assertStringContainsString('## Summary', $incident->body);
        $this->assertSame(IncidentStatus::Resolved, $incident->status);
        $this->assertSame(IncidentSource::Import, $incident->source);
    }

    public function test_a_report_without_an_impact_paragraph_is_imported_without_one(): void
    {
        $this->artisan('incidents:import', ['directory' => $this->directory(), '--source' => 'acme'])->assertSuccessful();

        $this->assertNull(Incident::query()->where('imported_from', 'acme/2026-07-08-storage-stall.md')->sole()->impact);
    }

    public function test_a_report_without_a_date_in_its_name_or_heading_is_skipped_with_the_reason(): void
    {
        $this->artisan('incidents:import', ['directory' => $this->directory(), '--source' => 'acme'])
            ->expectsOutputToContain('rename the copy to YYYY-MM-DD-ongoing-investigation.md')
            ->assertSuccessful();

        $this->assertFalse(Incident::query()->where('imported_from', 'acme/ongoing-investigation.md')->exists());
    }

    public function test_running_it_twice_imports_nothing_twice(): void
    {
        $this->artisan('incidents:import', ['directory' => $this->directory(), '--source' => 'acme'])->assertSuccessful();
        $this->artisan('incidents:import', ['directory' => $this->directory(), '--source' => 'acme'])
            ->expectsOutputToContain('already imported')
            ->assertSuccessful();

        $this->assertSame(2, Incident::count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->artisan('incidents:import', ['directory' => $this->directory(), '--source' => 'acme', '--dry-run' => true])
            ->expectsOutputToContain('would import')
            ->assertSuccessful();

        $this->assertSame(0, Incident::count());
    }

    public function test_the_imported_incidents_are_linked_to_the_project(): void
    {
        $project = Project::factory()->create(['slug' => 'acme-platform']);

        $this->artisan('incidents:import', ['directory' => $this->directory(), '--source' => 'acme', '--project' => 'acme-platform'])->assertSuccessful();

        $this->assertSame(2, $project->incidents()->count());
    }

    public function test_an_unknown_project_or_directory_fails_before_importing(): void
    {
        $this->artisan('incidents:import', ['directory' => $this->directory(), '--source' => 'acme', '--project' => 'nope'])->assertFailed();
        $this->artisan('incidents:import', ['directory' => '/does/not/exist', '--source' => 'acme'])->assertFailed();

        $this->assertSame(0, Incident::count());
    }

    public function test_the_source_repository_is_required(): void
    {
        $this->artisan('incidents:import', ['directory' => $this->directory()])
            ->expectsOutputToContain('--source')
            ->assertFailed();

        $this->assertSame(0, Incident::count());
    }

    public function test_the_same_file_name_from_two_repositories_is_imported_twice(): void
    {
        $this->artisan('incidents:import', ['directory' => $this->directory(), '--source' => 'acme'])->assertSuccessful();
        $this->artisan('incidents:import', ['directory' => $this->directory(), '--source' => 'other'])->assertSuccessful();

        $this->assertSame(4, Incident::count());
    }
}
