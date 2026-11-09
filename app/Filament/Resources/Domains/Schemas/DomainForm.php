<?php

namespace App\Filament\Resources\Domains\Schemas;

use App\Enums\CostPeriod;
use App\Enums\ProviderKind;
use App\Enums\ServerStatus;
use App\Filament\Resources\Providers\Schemas\ProviderForm;
use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\Domain;
use App\Models\DomainPrice;
use App\Models\Provider;
use App\Models\Server;
use App\Rules\NoSecretMaterial;
use App\Services\Costs\Money;
use BcMath\Number;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Once;

class DomainForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make(__('Domain'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Domain name'))
                            ->placeholder(__('example.com'))
                            ->required()
                            ->maxLength(255)
                            // A local zone may be a single label ("internal", "lan").
                            ->regex(fn (Get $get): string => $get('local_only') ? Domain::LOCAL_NAME_PATTERN : Domain::NAME_PATTERN)
                            ->validationMessages(['regex' => __('Enter just the domain name, such as example.com.')])
                            ->unique(ignoreRecord: true)
                            // Its extension picks the price from the list.
                            ->live(onBlur: true),
                        Select::make('client_id')
                            ->label(__('Belongs to client'))
                            ->relationship('client', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder(__('Own domain')),
                        Select::make('registrar_id')
                            ->label(__('Registrar'))
                            ->relationship('registrar', 'name', fn (Builder $query, ?Model $record) => $query->offering(ProviderKind::Registrar, $record?->registrar_id))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->hidden(fn (Get $get): bool => (bool) $get('local_only')),
                        // Before the DNS host, which it hides: so it does not move when chosen.
                        self::dnsServerField(),
                        Select::make('dns_provider_id')->validationAttribute(__('DNS host'))
                            ->label(__('DNS host'))
                            ->relationship('dnsProvider', 'name', fn (Builder $query, ?Model $record) => $query->offering(ProviderKind::Dns, $record?->dns_provider_id))
                            ->searchable()
                            ->preload()
                            // With a DNS server, the DNS host is its provider (Domain::booted()).
                            ->hidden(fn (Get $get): bool => filled($get('dns_server_id'))),
                        self::mailServerField(),
                        Toggle::make('local_only')
                            ->label(__('Only on the local network'))
                            ->explain(__('Only in your local DNS, such as home.arpa: no registrar, costs or renewal (they are cleared), and every name under it is local.'))
                            ->live()
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label(__('Notes'))
                            ->rows(3)->maxLength(10000)
                            ->rules([new NoSecretMaterial])
                            ->columnSpanFull(),
                    ]),
                // A local domain is registered nowhere (Domain::hasContract()).
                CostSchema::formSection(CostPeriod::Year, self::priceListAmount(...))->hidden(fn (Get $get): bool => (bool) $get('local_only')),
                RenewalSchema::formSection()->hidden(fn (Get $get): bool => (bool) $get('local_only')),
            ]);
    }

    public static function mailServerField(): Select
    {
        return self::serverField('mail_server_id', __('Mail server'))
            ->explain(__('Only when the mail runs somewhere other than the website\'s server, such as on a hosting package (a service like Google Workspace is a subscription). An outage there counts for the projects of the domain.'));
    }

    public static function dnsServerField(): Select
    {
        return self::serverField('dns_server_id', __('DNS server'))
            ->live()
            ->explain(__('Only when the DNS runs on a server you added, such as a hosting package or a nameserver of your own. Its provider becomes the DNS host, and an outage there counts for the projects of the domain.'))
            // State, so a hint: the helper text is the explanation's (explain()).
            ->hint(function (Get $get): ?string {
                if (blank($get('dns_server_id'))) {
                    return null;
                }

                // One query: this runs on every round trip of the form.
                $server = Server::query()
                    ->leftJoin('providers', 'providers.id', '=', 'servers.provider_id')
                    ->where('servers.id', $get('dns_server_id'))
                    ->first(['servers.id', 'providers.name as provider_name']);

                return match (true) {
                    $server === null => null,
                    $server->provider_name === null => __('No DNS host: this server has no provider.'),
                    default => __('DNS host: :provider, the provider of this server.', ['provider' => $server->provider_name]),
                };
            });
    }

    /**
     * Empty, the price list of the registrar counts (Domain::costAmount()):
     * the field shows that price, or that there is none and how to add it.
     */
    private static function priceListAmount(TextInput $amount): TextInput
    {
        return $amount
            ->live(onBlur: true)
            ->placeholder(function (Get $get): ?string {
                if (($key = self::priceListKey($get)) === null) {
                    return null;
                }

                $price = self::listPrice($get);

                // The field shows the currency symbol already.
                return $price === null
                    ? __('No price for .:extension at :registrar', ['extension' => $key['extension'], 'registrar' => $key['registrarName']])
                    : __(':amount from the price list', ['amount' => Money::number($price->cost_amount)]);
            })
            ->explain(fn (Get $get): ?string => self::priceListKey($get) === null ? null : __("Leave it empty to use the registrar's price list: a price change there counts here too."))
            // State, not an explanation: always shown, so not in the helper text
            // (explain()) or the hint (excluding VAT). An amount of its own that
            // is just the list price would not follow a price change.
            ->aboveContent(fn (Get $get): ?string => self::isListPrice($get) ? __('Same as the price list: leave it empty to follow price changes.') : null)
            ->hintAction(self::addToPriceListAction());
    }

    /**
     * The first domain with an extension fills the price list: the next ones
     * need no amount.
     */
    private static function addToPriceListAction(): Action
    {
        return Action::make('addToPriceList')
            ->label(__('Add to the price list'))
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (Get $schemaGet): bool => self::priceListKey($schemaGet) !== null && self::listPrice($schemaGet) === null)
            ->modalHeading(fn (Get $schemaGet): string => __('Add .:extension to the price list', ['extension' => self::priceListKey($schemaGet)['extension'] ?? '']))
            ->modalDescription(__('What a domain with this extension costs per year at this registrar, excluding VAT. It counts for each of its domains without an amount of their own.'))
            ->fillForm(fn (Get $schemaGet): array => ['cost_amount' => self::period($schemaGet) === CostPeriod::Month ? null : $schemaGet('cost_amount')])
            ->schema([ProviderForm::priceInput()])
            ->action(function (array $data, Get $schemaGet, Set $schemaSet): void {
                $key = self::priceListKey($schemaGet);

                if ($key === null) {
                    return;
                }

                DomainPrice::query()->firstOrCreate(
                    ['provider_id' => $key['registrar'], 'extension' => $key['extension']],
                    ['cost_amount' => $data['cost_amount']],
                );
                // The lookups of this request said there was none.
                Once::flush();

                // Its own amount was the price: now the list says it.
                $cleared = self::isListPrice($schemaGet);

                if ($cleared) {
                    $schemaSet('cost_amount', null);
                }

                Notification::make()
                    ->success()
                    ->title(__('Added .:extension to the price list.', ['extension' => $key['extension']]))
                    ->body($cleared ? __('Save the domain to keep its changes.') : null)
                    ->send();
            });
    }

    /**
     * The registrar and extension of the domain in the form, when a price
     * of the list can apply to it: a valid, registered name at a registrar
     * that exists.
     *
     * @return ?array{registrar: int, registrarName: string, extension: string}
     */
    private static function priceListKey(Get $get): ?array
    {
        $name = mb_strtolower(trim((string) $get('name')));

        if ($get('local_only') || blank($get('registrar_id')) || ! preg_match(Domain::NAME_PATTERN, $name) || mb_strlen(Domain::extensionOf($name)) > 63) {
            return null;
        }

        $registrarName = self::registrarName((int) $get('registrar_id'));

        return $registrarName === null ? null : [
            'registrar' => (int) $get('registrar_id'),
            'registrarName' => $registrarName,
            'extension' => DomainPrice::normalize(Domain::extensionOf($name)),
        ];
    }

    /**
     * Once per request: the placeholder, the explanation, the state line and
     * the action each ask.
     */
    private static function registrarName(int $id): ?string
    {
        return once(fn (): ?string => Provider::query()->whereKey($id)->value('name'));
    }

    private static function period(Get $get): ?CostPeriod
    {
        $period = $get('cost_period');

        return $period instanceof CostPeriod ? $period : CostPeriod::tryFrom((string) $period);
    }

    /**
     * Domain::listPrice() for the form, whose domain is not saved yet.
     */
    private static function listPrice(Get $get): ?DomainPrice
    {
        if (($key = self::priceListKey($get)) === null) {
            return null;
        }

        return self::findPrice($key['registrar'], $key['extension']);
    }

    /**
     * Once per request (registrarName()); addToPriceListAction() forgets it
     * after adding one.
     */
    private static function findPrice(int $registrar, string $extension): ?DomainPrice
    {
        return once(fn (): ?DomainPrice => DomainPrice::query()->where('provider_id', $registrar)->where('extension', $extension)->first());
    }

    /**
     * Its own amount is per year and just the list price. Only a plain
     * amount: a browser lets "1e3" through.
     */
    private static function isListPrice(Get $get): bool
    {
        $amount = trim((string) $get('cost_amount'));
        $price = self::listPrice($get);

        return $price !== null && preg_match('/^\d+(\.\d+)?$/', $amount)
            && self::period($get) === CostPeriod::Year
            && (new Number($amount))->compare(new Number($price->cost_amount)) === 0;
    }

    /**
     * Also in the bulk actions of the domains table: options rather than a
     * relationship, which needs a record. Not a server out of use, unless it
     * is the current one.
     */
    private static function serverField(string $column, string $label): Select
    {
        return Select::make($column)
            ->label($label)
            ->options(fn (?Model $record): array => Server::query()
                ->where(fn (Builder $query) => $query
                    ->where('status', '!=', ServerStatus::Decommissioned)
                    ->when($record?->{$column}, fn (Builder $query, int $id) => $query->orWhereKey($id)))
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all())
            ->searchable();
    }
}
