<?php

namespace Database\Factories;

use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord().'-'.fake()->numberBetween(1, 99),
            'status' => ServerStatus::Active,
            'public_ipv4' => fake()->unique()->ipv4(),
            'os_name' => 'Ubuntu',
            'os_version' => '24.04',
            'ssh_user' => 'deploy',
        ];
    }
}
