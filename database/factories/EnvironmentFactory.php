<?php

namespace Database\Factories;

use App\Enums\EnvironmentKind;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Environment>
 */
class EnvironmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Production',
            'kind' => EnvironmentKind::Production,
        ];
    }

    public function staging(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Staging',
            'kind' => EnvironmentKind::Staging,
        ]);
    }
}
