<?php

namespace App\Filament\Resources\Checks\Schemas;

use App\Filament\Resources\Hostnames\HostnameResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Support\Percentage;
use App\Models\Check;
use App\Services\Checks\Uptime;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class CheckInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('state')
                            ->label(__('Status'))
                            ->badge()
                            ->state(fn (Check $record): string => $record->enabled ? $record->state->getLabel() : __('Paused'))
                            ->color(fn (Check $record): string => $record->enabled ? $record->state->getColor() : 'gray'),
                        TextEntry::make('type')->label(__('Type'))->badge()->color('gray'),
                        TextEntry::make('subject')
                            ->label(__('Belongs to'))
                            ->state(fn (Check $record): ?string => $record->hostname?->fqdn ?? $record->server?->name)
                            ->url(fn (Check $record): ?string => match (true) {
                                $record->hostname !== null => HostnameResource::getUrl('view', ['record' => $record->hostname]),
                                $record->server !== null => ServerResource::getUrl('view', ['record' => $record->server]),
                                default => null,
                            }),
                        TextEntry::make('alert_level')->label(__('Alert')),
                        TextEntry::make('target')->label(__('Target'))->fontFamily(FontFamily::Mono)->copyable()->columnSpan(2),
                        // The one setting that weakens a check: say so on its page.
                        TextEntry::make('accept_any_certificate')
                            ->label(__('Certificate'))
                            ->state(__('Not verified'))
                            ->badge()
                            ->color('warning')
                            ->visible(fn (Check $record): bool => $record->acceptsAnyCertificate()),
                        TextEntry::make('interval_seconds')
                            ->label(__('How often'))
                            ->formatStateUsing(fn (int $state): string => CheckForm::intervals()[$state] ?? __('Every :seconds seconds', ['seconds' => $state])),
                        TextEntry::make('thresholds')
                            ->label(__('Thresholds'))
                            ->state(fn (Check $record): string => __('down after :failures, up after :successes', [
                                'failures' => $record->failure_threshold,
                                'successes' => $record->success_threshold,
                            ])),
                    ]),
                Section::make(__('Last check'))
                    ->columns(4)
                    ->schema([
                        TextEntry::make('last_checked_at')->label(__('When'))->since()->placeholder(__('Not yet')),
                        TextEntry::make('last_response_ms')->label(__('Response time'))->suffix(' ms')->placeholder('—'),
                        TextEntry::make('last_state_changed_at')->label(__('Status since'))->dateTime(__('formats.datetime'))->placeholder('—'),
                        TextEntry::make('last_error')->label(__('Error'))->placeholder('—')->color('danger'),
                    ]),
                Section::make(__('Uptime'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('uptime_day')
                            ->label(__('Last 24 hours'))
                            ->state(fn (Check $record): ?string => self::percentage(app(Uptime::class)->lastDay($record)))
                            ->placeholder(__('No results yet')),
                        TextEntry::make('uptime_week')
                            ->label(__('Last 7 days'))
                            ->state(fn (Check $record): ?string => self::percentage(app(Uptime::class)->lastDays($record, 7)))
                            ->placeholder(__('No results yet')),
                        TextEntry::make('uptime_month')
                            ->label(__('Last 30 days'))
                            ->state(fn (Check $record): ?string => self::percentage(app(Uptime::class)->lastDays($record, 30)))
                            ->placeholder(__('No results yet')),
                    ]),
            ]);
    }

    private static function percentage(?float $value): ?string
    {
        return $value === null ? null : Percentage::format($value);
    }
}
