<?php

namespace Database\Factories;

use App\Enums\CredentialKind;
use App\Models\CredentialReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CredentialReference>
 */
class CredentialReferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'label' => 'SSH '.fake()->domainWord(),
            'kind' => CredentialKind::SshKey,
            'username' => 'deploy',
            'vault_item_id' => fake()->uuid(),
        ];
    }
}
