<?php

namespace Database\Factories;

use App\Enums\ProviderKind;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Provider>
 */
class ProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'kinds' => [ProviderKind::Hosting->value],
            'panel_url' => fake()->url(),
        ];
    }

    public function registrar(): static
    {
        return $this->state(fn (array $attributes) => [
            'kinds' => [ProviderKind::Registrar->value, ProviderKind::Dns->value],
        ]);
    }
}
