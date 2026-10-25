<?php

namespace Tests\Feature;

use Filament\Support\Facades\FilamentTimezone;
use Tests\TestCase;

class TimezoneTest extends TestCase
{
    public function test_timestamps_are_stored_in_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', now()->getTimezone()->getName());
    }

    public function test_filament_shows_times_in_the_display_timezone(): void
    {
        $this->assertSame('Europe/Amsterdam', FilamentTimezone::get());
    }
}
