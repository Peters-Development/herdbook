<?php

namespace App\Filament\Resources\Providers\Schemas;

use App\Enums\ProviderKind;
use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Provider;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProviderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('kinds')
                            ->label(__('Supplies'))
                            ->state(fn (Provider $record): array => array_map(
                                fn (string $kind): string => ProviderKind::from($kind)->getLabel(),
                                $record->kinds ?? [],
                            ))
                            ->badge(),
                        TextEntry::make('panel_url')->label(__('Control panel'))->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)->placeholder('—'),
                        TextEntry::make('website_url')->label(__('Website'))->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)->placeholder('—'),
                        TextEntry::make('support_email')->label(__('Support email'))->copyable()->placeholder('—'),
                        TextEntry::make('support_phone')->label(__('Support phone'))->copyable()->placeholder('—'),
                        TextEntry::make('renewal_lead_days')
                            ->label(__('Renewal lead time (days)'))
                            ->placeholder(__(':days (default)', ['days' => config('herdbook.renewal.default_lead_days')])),
                        // Each number opens the list behind it.
                        TextEntry::make('servers_count')
                            ->label(__('Servers'))
                            ->state(fn (Provider $record): int => $record->servers()->count())
                            ->url(fn (Provider $record, int $state): ?string => $state > 0 ? ServerResource::getUrl('index', ['filters' => ['provider_id' => ['value' => $record->id]]]) : null),
                        TextEntry::make('domains_count')
                            ->label(__('Registered domains'))
                            ->state(fn (Provider $record): int => $record->registeredDomains()->count())
                            ->url(fn (Provider $record, int $state): ?string => $state > 0 ? DomainResource::getUrl('index', ['filters' => ['registrar_id' => ['value' => $record->id]]]) : null),
                        TextEntry::make('dns_domains_count')
                            ->label(__('DNS domains'))
                            ->state(fn (Provider $record): int => $record->dnsDomains()->count())
                            ->url(fn (Provider $record): string => DomainResource::getUrl('index', ['filters' => ['dns_provider_id' => ['value' => $record->id]]]))
                            ->visible(fn (int $state): bool => $state > 0),
                        TextEntry::make('subscriptions_count')
                            ->label(__('Subscriptions'))
                            ->state(fn (Provider $record): int => $record->subscriptions()->count())
                            ->url(fn (Provider $record, int $state): ?string => $state > 0 ? SubscriptionResource::getUrl('index', ['filters' => ['provider_id' => ['value' => $record->id]]]) : null),
                        // Only for an internet provider: the lines it delivers.
                        TextEntry::make('networks')
                            ->label(__('Networks'))
                            ->state(fn (Provider $record): array => $record->networks()->orderBy('name')->pluck('name')->all())
                            ->badge()
                            ->visible(fn (Provider $record): bool => $record->networks()->exists()),
                        TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
