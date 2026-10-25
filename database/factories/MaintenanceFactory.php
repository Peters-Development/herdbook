<?php

namespace Database\Factories;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceTask;
use App\Models\Maintenance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Maintenance>
 */
class MaintenanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tasks' => [MaintenanceTask::Updates->value],
            'status' => MaintenanceStatus::Done,
            'starts_at' => now()->subDay(),
        ];
    }

    public function planned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MaintenanceStatus::Planned,
            'starts_at' => now()->addWeek(),
        ]);
    }
}
