<?php

namespace App\Filament\Resources\Domains\Schemas;

use App\Enums\ServerStatus;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\Domain;
use App\Models\Provider;
use App\Models\Server;
use Closure;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class DomainInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make(__('Domain'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('client.name')->label(__('Belongs to client'))->placeholder(__('Own domain')),
                        self::providerEntry('registrar', __('Registrar'))->hidden(fn (Domain $record): bool => $record->local_only),
                        self::dnsHostEntry(fn (Domain $record): Domain => $record),
                        self::serverEntry('dnsServer', __('DNS server')),
                        self::serverEntry('mailServer', __('Mail server')),
                        CostSchema::entry()->hidden(fn (Domain $record): bool => $record->local_only),
                        IconEntry::make('local_only')->label(__('Only on the local network'))->boolean()->visible(fn (Domain $record): bool => $record->local_only),
                        TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
                    ]),
                RenewalSchema::infolistSection(),
            ]);
    }

    /**
     * Who serves the DNS of the domain (Domain::dnsHost()), linked. Also on
     * the page of a hostname.
     *
     * @param  Closure(Model): Domain  $domain
     */
    public static function dnsHostEntry(Closure $domain): TextEntry
    {
        return TextEntry::make('dns_host')
            ->label(__('DNS host'))
            ->state(fn (Model $record): ?string => $domain($record)->dnsHostName())
            ->url(fn (Model $record): ?string => match (true) {
                $domain($record)->dnsHost() instanceof Provider => ProviderResource::getUrl('view', ['record' => $domain($record)->dnsHost()]),
                $domain($record)->dnsHost() instanceof Server => ServerResource::getUrl('view', ['record' => $domain($record)->dnsHost()]),
                default => null,
            })
            ->placeholder('—');
    }

    private static function providerEntry(string $relation, string $label): TextEntry
    {
        return TextEntry::make("{$relation}.name")
            ->label($label)
            ->url(fn (Domain $record): ?string => $record->{$relation} ? ProviderResource::getUrl('view', ['record' => $record->{$relation}]) : null)
            ->placeholder('—');
    }

    private static function serverEntry(string $relation, string $label): TextEntry
    {
        return TextEntry::make("{$relation}.name")
            ->label($label)
            ->url(fn (Domain $record): ?string => $record->{$relation} ? ServerResource::getUrl('view', ['record' => $record->{$relation}]) : null)
            // Its checks are paused: say so, or the link goes unnoticed.
            ->suffix(fn (Domain $record): ?string => $record->{$relation}?->status === ServerStatus::Decommissioned ? ' ('.ServerStatus::Decommissioned->getLabel().')' : null)
            ->placeholder('—');
    }
}
