<?php

namespace App\Filament\Resources\Checks\Schemas;

use App\Enums\AlertLevel;
use App\Enums\CheckType;
use App\Filament\Support\RequestedRecord;
use App\Models\Check;
use App\Models\Hostname;
use App\Models\Server;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class CheckForm
{
    /**
     * @return array<int, string>
     */
    public static function intervals(): array
    {
        return [
            60 => __('Every minute'),
            120 => __('Every 2 minutes'),
            300 => __('Every 5 minutes'),
            900 => __('Every 15 minutes'),
            3600 => __('Every hour'),
            21600 => __('Every 6 hours'),
            86400 => __('Every day'),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('What to check'))
                    ->columns(2)
                    ->schema([
                        Select::make('type')
                            ->label(__('Type'))
                            ->options(CheckType::class)
                            ->default(CheckType::Http)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, CheckType|string|null $state, string $operation): void {
                                $type = self::type($state);

                                if ($type === null) {
                                    return;
                                }

                                // On edit, a chosen interval and thresholds stay.
                                if ($operation === 'create') {
                                    $set('interval_seconds', $type->defaultInterval());
                                    $set('failure_threshold', $type->defaultFailureThreshold());
                                    $set('success_threshold', $type->defaultSuccessThreshold());
                                }

                                // The target in the shape the new type expects.
                                if (filled($get('target'))) {
                                    $set('target', self::targetFor($type, Check::hostOf((string) $get('target')), (string) $get('target')));
                                } else {
                                    self::suggestTarget($set, $get);
                                }
                            }),
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->placeholder(__('Default: type + host'))
                            ->maxLength(255),
                        Select::make('hostname_id')
                            ->label(__('Hostname'))
                            ->relationship('hostname', 'fqdn')
                            ->searchable()
                            ->preload()
                            // "?hostname=12" from the "New check" button on a page.
                            ->default(fn (): ?int => RequestedRecord::id('hostname', Hostname::class))
                            ->requiredWithout('server_id')
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, string $operation): void {
                                self::suggestTarget($set, $get);

                                if ($operation === 'create') {
                                    $set('settings.accept_any_certificate', self::isLocal($get('hostname_id')));
                                }
                            })
                            ->rules([fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if (filled($value) && filled($get('server_id'))) {
                                    $fail(__('Choose a hostname or a server, not both.'));
                                }
                            }]),
                        Select::make('server_id')
                            ->label(__('Or server'))
                            ->relationship('server', 'name')
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => RequestedRecord::id('server', Server::class))
                            ->requiredWithout('hostname_id'),
                        TextInput::make('target')
                            ->label(__('Target'))
                            ->extraInputAttributes(['class' => 'fi-font-mono'])
                            ->required()
                            ->maxLength(500)
                            ->placeholder(fn (Get $get): string => self::targetPlaceholder($get('type')))
                            ->rules([fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $message = self::targetError(self::type($get('type')), (string) $value);

                                if ($message !== null) {
                                    $fail($message);
                                }
                            }])
                            ->columnSpanFull(),
                        TextInput::make('settings.keyword')
                            ->label(__('Keyword'))
                            ->helperText(__('Must appear on the page (not case-sensitive).'))
                            ->visible(fn (Get $get): bool => self::type($get('type')) === CheckType::Keyword)
                            ->required(fn (Get $get): bool => self::type($get('type')) === CheckType::Keyword)
                            ->maxLength(255),
                        TextInput::make('settings.expected_status')
                            ->label(__('Expected status code'))
                            ->placeholder(fn (Get $get): string => self::type($get('type')) === CheckType::Http && filled($get('settings.redirects_to')) ? __('Default: 300–399') : __('Default: 200–299'))
                            ->integer()
                            ->minValue(100)
                            ->maxValue(599)
                            ->rules([fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if (self::type($get('type')) === CheckType::Http && filled($get('settings.redirects_to')) && ((int) $value < 300 || (int) $value > 399)) {
                                    $fail(__('A redirect has a status code from 300 to 399.'));
                                }
                            }])
                            ->visible(fn (Get $get): bool => in_array(self::type($get('type')), [CheckType::Http, CheckType::Keyword], true)),
                        TextInput::make('settings.redirects_to')
                            ->label(__('Must redirect to'))
                            ->placeholder(__('Default: the page itself must work'))
                            ->helperText(__('The check then does not follow the redirect, but checks that it points to this name.'))
                            ->extraInputAttributes(['class' => 'fi-font-mono'])
                            ->maxLength(253)
                            ->regex('/^'.Hostname::NAME_PATTERN.'$/i')
                            ->validationMessages(['regex' => __('Enter just the name, like example.com (no https:// or path).')])
                            ->live(onBlur: true)
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtolower(trim($state)) : null)
                            ->visible(fn (Get $get): bool => self::type($get('type')) === CheckType::Http),
                        Toggle::make('settings.accept_any_certificate')
                            ->label(__('Accept any certificate'))
                            // A new check of a local name: its certificate is one the host does not trust.
                            ->default(fn (): bool => self::isLocal(RequestedRecord::id('hostname', Hostname::class)))
                            ->helperText(__("For a self-signed certificate or one of a CA of your own, like Caddy's on the local network: the page must still answer, but its certificate is not verified."))
                            ->visible(fn (Get $get): bool => self::type($get('type'))?->isHttp() ?? false)
                            ->columnSpanFull(),
                        TextInput::make('settings.warn_days')
                            ->label(__('Start warning'))
                            ->suffix(__('days before expiry'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(90)
                            ->default(14)
                            ->visible(fn (Get $get): bool => self::type($get('type')) === CheckType::Tls),
                    ]),
                Section::make(__('Behavior'))
                    ->columns(3)
                    ->schema([
                        Select::make('interval_seconds')
                            ->label(__('How often'))
                            ->options(self::intervals())
                            ->default(60)
                            ->required(),
                        TextInput::make('failure_threshold')
                            ->label(__('Down after'))
                            ->suffix(__('failed checks in a row'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10)
                            ->default(3)
                            ->required(),
                        TextInput::make('success_threshold')
                            ->label(__('Up again after'))
                            ->suffix(__('successful checks in a row'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10)
                            ->default(CheckType::Http->defaultSuccessThreshold())
                            ->required(),
                        Select::make('alert_level')
                            ->label(__('Alert'))
                            ->options(AlertLevel::class)
                            ->default(AlertLevel::Normal)
                            ->required()
                            ->columnSpan(2),
                        Toggle::make('enabled')
                            ->label(__('Active'))
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }

    private static function type(mixed $state): ?CheckType
    {
        return $state instanceof CheckType ? $state : CheckType::tryFrom((string) $state);
    }

    /**
     * Whether hostname $id is only on the local network.
     */
    private static function isLocal(mixed $id): bool
    {
        return filled($id) && (bool) Hostname::query()->whereKey($id)->value('local_only');
    }

    /**
     * Fills the target from the chosen hostname while it is still empty.
     */
    private static function suggestTarget(Set $set, Get $get): void
    {
        $type = self::type($get('type'));
        $fqdn = Hostname::query()->whereKey($get('hostname_id'))->value('fqdn');

        if ($type === null || $fqdn === null || filled($get('target'))) {
            return;
        }

        $set('target', self::targetFor($type, $fqdn));
    }

    /**
     * $host as a target of $type; an http(s) URL is kept when the type is
     * HTTP and $current already is one.
     */
    private static function targetFor(CheckType $type, string $host, ?string $current = null): string
    {
        return match ($type) {
            CheckType::Http, CheckType::Keyword => $current !== null && preg_match('#^https?://#i', $current) ? $current : "https://{$host}/",
            CheckType::Tls => "{$host}:443",
            CheckType::Tcp => $current !== null && preg_match('/:\d+$/', $current) && ! str_contains($current, '://') ? $current : "{$host}:443",
            CheckType::Dns, CheckType::Ping => $host,
        };
    }

    private static function targetPlaceholder(mixed $type): string
    {
        return match (self::type($type)) {
            CheckType::Http, CheckType::Keyword => 'https://app.example.com/',
            CheckType::Tls => 'app.example.com:443',
            CheckType::Tcp => 'db.example.com:3306',
            CheckType::Dns => 'app.example.com',
            CheckType::Ping => '203.0.113.10',
            default => '',
        };
    }

    private const string HOST = '(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*|\d{1,3}(?:\.\d{1,3}){3}|\[[0-9a-f:.]+\])';

    private static function targetError(?CheckType $type, string $target): ?string
    {
        $host = '/^'.self::HOST.'$/i';
        $hostAndPort = '/^'.self::HOST.':\d{1,5}$/i';
        $hostWithOptionalPort = '/^'.self::HOST.'(?::\d{1,5})?$/i';

        $port = preg_match('/:(\d+)$/', $target, $matches) === 1 ? (int) $matches[1] : null;

        return match (true) {
            $port !== null && ($port < 1 || $port > 65535) && ! $type?->isHttp() => __('A port is between 1 and 65535.'),
            $type?->isHttp() && ! Check::isValidHttpTarget($target) => __('A full URL, starting with https:// or http://, with a valid port and no credentials.'),
            $type === CheckType::Tcp && ! preg_match($hostAndPort, $target) => __('Host and port, like db.example.com:3306.'),
            $type === CheckType::Tls && ! preg_match($hostWithOptionalPort, $target) => __('A name, optionally with a port, like app.example.com:443 (no https://).'),
            in_array($type, [CheckType::Dns, CheckType::Ping], true) && ! preg_match($host, $target) && filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false => __('Just a name or IP address, no port or path.'),
            default => null,
        };
    }
}
