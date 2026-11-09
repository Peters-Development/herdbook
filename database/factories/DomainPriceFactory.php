<?php

namespace Database\Factories;

use App\Models\DomainPrice;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DomainPrice>
 */
class DomainPriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory()->registrar(),
            'extension' => fake()->unique()->lexify('???'),
            'cost_amount' => '9.95',
        ];
    }
}
