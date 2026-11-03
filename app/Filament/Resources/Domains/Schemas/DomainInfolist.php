<?php

namespace App\Filament\Resources\Domains\Schemas;

use App\Enums\ServerStatus;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\Domain;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                        TextEntry::make('registrar.name')->label(__('Registrar'))->placeholder('—')->hidden(fn (Domain $record): bool => $record->local_only),
                        TextEntry::make('dnsProvider.name')->label(__('DNS host'))->placeholder('—'),
                        TextEntry::make('mailServer.name')
                            ->label(__('Mail server'))
                            ->url(fn (Domain $record): ?string => $record->mail_server_id ? ServerResource::getUrl('view', ['record' => $record->mail_server_id]) : null)
                            // Its checks are paused: say so, or the link goes unnoticed.
                            ->suffix(fn (Domain $record): ?string => $record->mailServer?->status === ServerStatus::Decommissioned ? ' ('.ServerStatus::Decommissioned->getLabel().')' : null)
                            ->placeholder('—'),
                        CostSchema::entry()->hidden(fn (Domain $record): bool => $record->local_only),
                        IconEntry::make('local_only')->label(__('Only on the local network'))->boolean()->visible(fn (Domain $record): bool => $record->local_only),
                        TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
                    ]),
                RenewalSchema::infolistSection(),
            ]);
    }
}
