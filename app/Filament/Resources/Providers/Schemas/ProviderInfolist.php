<?php

namespace App\Filament\Resources\Providers\Schemas;

use App\Enums\ProviderKind;
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
                        TextEntry::make('servers_count')->label(__('Servers'))->state(fn (Provider $record): int => $record->servers()->count()),
                        TextEntry::make('domains_count')->label(__('Domains'))->state(fn (Provider $record): int => $record->registeredDomains()->count()),
                        TextEntry::make('subscriptions_count')->label(__('Subscriptions'))->state(fn (Provider $record): int => $record->subscriptions()->count()),
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
