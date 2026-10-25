<?php

namespace App\Models;

use App\Enums\CredentialKind;
use App\Models\Concerns\LinksToInventory;
use App\Models\Concerns\RecordsActivity;
use Database\Factories\CredentialReferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Where a login lives, never the secret itself: label, username, host and
 * the id of the item in Vaultwarden.
 */
#[Fillable(['label', 'kind', 'username', 'host', 'port', 'vault_item_id', 'notes'])]
class CredentialReference extends Model
{
    /** @use HasFactory<CredentialReferenceFactory> */
    use HasFactory, LinksToInventory, RecordsActivity;

    protected function linkMorphName(): string
    {
        return 'credentialable';
    }

    /**
     * The item in the Vaultwarden web vault, or null without an item id or
     * without a configured vault URL.
     */
    public function vaultUrl(): ?string
    {
        $base = config('herdbook.vault_url');

        if (blank($base) || blank($this->vault_item_id)) {
            return null;
        }

        return rtrim($base, '/').'/#/vault?itemId='.$this->vault_item_id;
    }

    /**
     * Accepts a bare item id or a pasted web vault link ("…?itemId=<uuid>")
     * and returns the item id, or the input unchanged when it holds neither.
     */
    public static function extractVaultItemId(?string $input): ?string
    {
        if (blank($input)) {
            return null;
        }

        $input = trim($input);

        if (preg_match('/[?&]itemId=([0-9a-f-]{36})/i', $input, $matches) === 1) {
            return Str::lower($matches[1]);
        }

        return Str::isUuid($input) ? Str::lower($input) : $input;
    }

    protected function casts(): array
    {
        return [
            'kind' => CredentialKind::class,
            'port' => 'integer',
        ];
    }
}
