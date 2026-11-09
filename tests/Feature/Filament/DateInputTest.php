<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * Dates can be typed: the browser's own date input, not a calendar that
 * only takes clicks.
 */
class DateInputTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_an_expiry_date_can_be_typed(): void
    {
        $this->signIn();

        $this->get(DomainResource::getUrl('edit', ['record' => Domain::factory()->create()]))
            ->assertOk()
            ->assertSee('type="date"', false);
    }

    public function test_the_dates_and_times_of_an_incident_and_maintenance_can_be_typed(): void
    {
        $this->signIn();

        $this->get(IncidentResource::getUrl('create'))
            ->assertOk()
            ->assertSee('type="date"', false)
            ->assertSee('type="datetime-local"', false);

        $this->get(MaintenanceResource::getUrl('create'))
            ->assertOk()
            ->assertSee('type="datetime-local"', false);
    }
}
