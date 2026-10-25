<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Keeps private keys out of free-text fields: secrets belong in Vaultwarden
 * (docs/development.md#no-secrets). Public keys and certificates pass.
 */
class NoSecretMaterial implements ValidationRule
{
    private const string PRIVATE_KEY = '/-----BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY(?: BLOCK)?-----|PuTTY-User-Key-File-\d+:/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match(self::PRIVATE_KEY, $value) === 1) {
            $fail(__('This looks like a private key. Keys and passwords belong in Vaultwarden, not here.'));
        }
    }
}
