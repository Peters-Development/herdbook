<?php

namespace Tests\Feature\Inventory;

use App\Models\CredentialReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialReferenceTest extends TestCase
{
    use RefreshDatabase;

    private const string ITEM_ID = '0f8e2a6c-3b1d-4c5e-9a7f-1d2c3b4a5e6f';

    public function test_the_item_id_is_taken_from_a_pasted_web_vault_link(): void
    {
        $this->assertSame(self::ITEM_ID, CredentialReference::extractVaultItemId('https://vault.example.ts.net/#/vault?itemId='.strtoupper(self::ITEM_ID).'&action=view'));
    }

    public function test_a_bare_item_id_is_kept(): void
    {
        $this->assertSame(self::ITEM_ID, CredentialReference::extractVaultItemId('  '.self::ITEM_ID.' '));
    }

    public function test_input_without_an_item_id_is_returned_unchanged_for_validation_to_refuse(): void
    {
        $this->assertSame('not-an-id', CredentialReference::extractVaultItemId('not-an-id'));
        $this->assertNull(CredentialReference::extractVaultItemId(''));
    }

    public function test_the_vault_link_needs_both_a_vault_url_and_an_item_id(): void
    {
        $credential = CredentialReference::factory()->make(['vault_item_id' => self::ITEM_ID]);

        config(['herdbook.vault_url' => null]);
        $this->assertNull($credential->vaultUrl());

        config(['herdbook.vault_url' => 'https://vault.example.ts.net/']);
        $this->assertSame('https://vault.example.ts.net/#/vault?itemId='.self::ITEM_ID, $credential->vaultUrl());

        $credential->vault_item_id = null;
        $this->assertNull($credential->vaultUrl());
    }
}
