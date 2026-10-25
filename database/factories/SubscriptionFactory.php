<?php

namespace Database\Factories;

use App\Enums\CostPeriod;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'cost_amount' => '12.00',
            'cost_period' => CostPeriod::Month,
            'expires_on' => null,
            'auto_renews' => true,
        ];
    }
}
