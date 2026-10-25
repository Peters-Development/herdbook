<?php

namespace Database\Factories;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'severity' => IncidentSeverity::Sev2,
            'status' => IncidentStatus::Open,
            'occurred_on' => now()->toDateString(),
            'source' => IncidentSource::Manual,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IncidentStatus::Resolved,
        ]);
    }
}
