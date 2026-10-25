<?php

namespace Database\Factories;

use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord().'.test',
            'expires_on' => now()->addMonths(6)->toDateString(),
            'auto_renews' => true,
        ];
    }
}
