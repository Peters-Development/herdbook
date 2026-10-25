<?php

namespace App\Filament\Resources\Hostnames\Schemas;

use App\Models\Domain;
use App\Models\Environment;
use App\Models\Hostname;
use App\Rules\NoSecretMaterial;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class HostnameForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components(self::components());
    }

    /**
     * Shared with the hostnames relation manager of a domain, where the
     * domain is fixed ($domain) and not a field.
     *
     * @return array<int, mixed>
     */
    public static function components(?Domain $domain = null): array
    {
        return [
            ...($domain === null ? [
                Select::make('domain_id')
                    ->label(__('Domain'))
                    ->relationship('domain', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live(),
            ] : []),
            TextInput::make('fqdn')
                ->label(__('Hostname'))
                ->extraInputAttributes(['class' => 'fi-font-mono'])
                ->placeholder($domain ? "app.{$domain->name}" : 'app.example.com')
                ->required()
                ->maxLength(253)
                ->unique(ignoreRecord: true)
                // A name, not a URL: a wildcard is allowed, a scheme or path is not.
                ->regex('/^(\*\.)?'.Hostname::NAME_PATTERN.'$/i')
                ->validationMessages(['regex' => __('Enter just the name, like app.example.com (no https:// or path).')])
                ->rules([fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($domain, $get): void {
                    $domainName = $domain?->name ?? Domain::query()->whereKey($get('domain_id'))->value('name');

                    if ($domainName !== null && ! Hostname::belongsToDomain((string) $value, $domainName)) {
                        $fail(__('The hostname must end in :domain.', ['domain' => $domainName]));
                    }
                }]),
            // All environments as "Project · Environment": searching finds a project
            // by name, and there is no cut-off at 50 (a few hundred at most).
            Select::make('environment_id')
                ->label(__('Points to environment'))
                ->options(fn (): array => Environment::query()
                    ->with('project')
                    ->get()
                    ->sortBy(fn (Environment $environment): string => mb_strtolower($environment->full_name))
                    ->mapWithKeys(fn (Environment $environment): array => [$environment->id => $environment->full_name])
                    ->all())
                ->searchable()
                ->exists('environments', 'id'),
            TextInput::make('purpose')
                ->label(__('Purpose'))
                ->placeholder('website, admin, api, mail')
                ->maxLength(255),
            TextInput::make('redirects_to')
                ->label(__('Redirects to'))
                ->extraInputAttributes(['class' => 'fi-font-mono'])
                ->placeholder($domain->name ?? 'example.com')
                ->helperText(__('Only if this name sends visitors elsewhere, like www to the name without www. Monitoring then checks the redirect.'))
                ->maxLength(253)
                ->datalist(fn (): array => Hostname::query()->where('fqdn', 'not like', '*.%')->orderBy('fqdn')->pluck('fqdn')->all())
                ->regex('/^'.Hostname::NAME_PATTERN.'$/i')
                ->validationMessages(['regex' => __('Enter just the name, like example.com (no https:// or path).')])
                ->rules([fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    if (mb_strtolower(trim((string) $value)) === mb_strtolower(trim((string) $get('fqdn')))) {
                        $fail(__('A name cannot redirect to itself.'));
                    }
                }]),
            Toggle::make('behind_proxy')
                ->label(__('Behind a proxy (Cloudflare)'))
                ->helperText(__('The DNS record then does not point to the server itself.'))
                ->inline(false),
            // Under a local domain every name is local (Hostname::booted()).
            Toggle::make('local_only')
                ->label(__('Only on the local network'))
                ->helperText(__("A name in your local DNS only, with a self-signed certificate or one of a CA of your own (like Caddy's). Turn on monitoring then creates just a check of the page that accepts its certificate."))
                ->hidden(fn (Get $get): bool => $domain?->local_only ?? (filled($get('domain_id')) && (bool) Domain::query()->whereKey($get('domain_id'))->value('local_only')))
                ->inline(false),
            TagsInput::make('expected_ips')
                ->label(__('Expected IP addresses'))
                ->placeholder(__("Default: those of the environment's servers"))
                ->nestedRecursiveRules(['ip'])
                ->columnSpanFull(),
            Textarea::make('notes')
                ->label(__('Notes'))
                ->rows(2)->maxLength(10000)
                ->rules([new NoSecretMaterial])
                ->columnSpanFull(),
        ];
    }
}
