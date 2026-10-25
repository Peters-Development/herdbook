<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\Hostname;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hostname>
 */
class HostnameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'fqdn' => fn (array $attributes) => fake()->unique()->domainWord().'.'.Domain::find($attributes['domain_id'])->name,
        ];
    }
}
