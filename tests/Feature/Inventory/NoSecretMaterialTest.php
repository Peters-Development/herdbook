<?php

namespace Tests\Feature\Inventory;

use App\Rules\NoSecretMaterial;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NoSecretMaterialTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function privateKeys(): array
    {
        return [
            'openssh' => ["-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n-----END OPENSSH PRIVATE KEY-----"],
            'rsa' => ["see below\n-----BEGIN RSA PRIVATE KEY-----\nMIIEow\n-----END RSA PRIVATE KEY-----"],
            'pkcs8' => ['-----BEGIN PRIVATE KEY-----'],
            'encrypted pkcs8' => ['-----BEGIN ENCRYPTED PRIVATE KEY-----'],
            'pgp' => ['-----BEGIN PGP PRIVATE KEY BLOCK-----'],
            'putty' => ["PuTTY-User-Key-File-3: ssh-ed25519\nEncryption: none"],
        ];
    }

    #[DataProvider('privateKeys')]
    public function test_a_private_key_is_refused(string $value): void
    {
        $validator = Validator::make(['notes' => $value], ['notes' => [new NoSecretMaterial]]);

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('Vaultwarden', $validator->errors()->first('notes'));
    }

    /** @return array<string, array{string}> */
    public static function harmlessText(): array
    {
        return [
            'public key' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI deploy@host'],
            'certificate' => ["-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----"],
            'plain text' => ['Inloggen via het panel, zie Vaultwarden.'],
        ];
    }

    #[DataProvider('harmlessText')]
    public function test_other_text_passes(string $value): void
    {
        $this->assertTrue(Validator::make(['notes' => $value], ['notes' => [new NoSecretMaterial]])->passes());
    }
}
