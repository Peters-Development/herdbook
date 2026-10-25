<?php

namespace Database\Factories;

use App\Models\Network;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Network>
 */
class NetworkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' office',
            'subnet' => '192.168.1.0/24',
            'gateway' => '192.168.1.1',
        ];
    }
}
