<?php

namespace App\Filament\Resources\CredentialReferences\Schemas;

use App\Enums\CredentialKind;
use App\Filament\Support\LinkSchema;
use App\Models\CredentialReference;
use App\Rules\NoSecretMaterial;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A reference, never the secret: passwords and keys stay in Vaultwarden.
 */
class CredentialReferenceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Credential'))
                    ->description(__('Only where to find the login. Passwords and keys belong in Vaultwarden, not here.'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('label')
                            ->label(__('Name'))
                            ->placeholder('SSH deploy app-1')
                            ->required()
                            ->maxLength(255)
                            ->rules([new NoSecretMaterial]),
                        Select::make('kind')->label(__('Type'))->options(CredentialKind::class)->required(),
                        TextInput::make('username')->label(__('Username'))->maxLength(255)->rules([new NoSecretMaterial]),
                        TextInput::make('host')->label(__('Host'))->extraInputAttributes(['class' => 'fi-font-mono'])->maxLength(255)->rules([new NoSecretMaterial]),
                        TextInput::make('port')->label(__('Port'))->integer()->minValue(1)->maxValue(65535),
                        TextInput::make('vault_item_id')
                            ->label(__('Vaultwarden item'))
                            ->placeholder(__('Paste the link to the item, or its ID'))
                            ->helperText(__('Open the item in the web vault and copy the link from the address bar.'))
                            ->dehydrateStateUsing(fn (?string $state): ?string => CredentialReference::extractVaultItemId($state))
                            ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                $itemId = CredentialReference::extractVaultItemId($value);

                                if ($itemId !== null && ! Str::isUuid($itemId)) {
                                    $fail(__('This is neither a link to a Vaultwarden item nor an item ID.'));
                                }
                            }]),
                        Textarea::make('notes')
                            ->label(__('Notes'))
                            ->rows(3)->maxLength(10000)
                            ->rules([new NoSecretMaterial])
                            ->columnSpanFull(),
                    ]),
                LinkSchema::formSection(),
            ]);
    }
}
