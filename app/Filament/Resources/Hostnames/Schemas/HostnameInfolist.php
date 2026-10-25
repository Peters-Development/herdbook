<?php

namespace App\Filament\Resources\Hostnames\Schemas;

use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Resources\Hostnames\HostnameResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Hostname;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class HostnameInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('domain.name')
                            ->label(__('Domain'))
                            ->url(fn (Hostname $record): string => DomainResource::getUrl('view', ['record' => $record->domain])),
                        TextEntry::make('environment')
                            ->label(__('Points to'))
                            ->state(fn (Hostname $record): ?string => $record->environment?->full_name)
                            ->url(fn (Hostname $record): ?string => $record->environment
                                ? ProjectResource::getUrl('view', ['record' => $record->environment->project_id])
                                : null)
                            ->placeholder(__('No environment')),
                        TextEntry::make('purpose')->label(__('Purpose'))->placeholder('—'),
                        TextEntry::make('redirects_to')
                            ->label(__('Redirects to'))
                            ->fontFamily(FontFamily::Mono)
                            ->url(fn (Hostname $record): ?string => $record->redirectTarget
                                ? HostnameResource::getUrl('view', ['record' => $record->redirectTarget])
                                : null)
                            ->placeholder('—'),
                        IconEntry::make('behind_proxy')->label(__('Behind a proxy'))->boolean(),
                        IconEntry::make('local_only')->label(__('Only on the local network'))->boolean(),
                        TextEntry::make('expected_ips')->label(__('Expected IP addresses'))->badge()->fontFamily(FontFamily::Mono)->placeholder(__("Those of the environment's servers")),
                        TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
