<?php

namespace Database\Factories;

use App\Enums\AlertLevel;
use App\Enums\CheckType;
use App\Models\Check;
use App\Models\Hostname;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Check>
 */
class CheckFactory extends Factory
{
    public function definition(): array
    {
        return [
            'hostname_id' => Hostname::factory(),
            'type' => CheckType::Http,
            'target' => fn (array $attributes): string => 'https://'.Hostname::find($attributes['hostname_id'])->fqdn.'/',
            'alert_level' => AlertLevel::Normal,
        ];
    }

    public function critical(): static
    {
        return $this->state(fn (array $attributes) => ['alert_level' => AlertLevel::Critical]);
    }

    public function forServer(?Server $server = null, CheckType $type = CheckType::Ping): static
    {
        return $this->state(fn (array $attributes) => [
            'hostname_id' => null,
            'server_id' => $server?->id ?? Server::factory(),
            'type' => $type,
            'target' => '203.0.113.10',
        ]);
    }

    public function ofType(CheckType $type, string $target): static
    {
        return $this->state(fn (array $attributes) => ['type' => $type, 'target' => $target]);
    }
}
