<?php

namespace App\Filament\Support;

use App\Enums\ServerRole;
use App\Services\Impact\ImpactedEnvironment;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * "Affected by an outage" on the page of a server or a network: the
 * environments that go down with it (ImpactAnalyzer), and how.
 */
final class ImpactSchema
{
    /**
     * @param  Closure(Model): Collection<int, ImpactedEnvironment>  $impacts
     */
    public static function section(string $description, Closure $impacts): Section
    {
        return Section::make(__('Affected by an outage'))
            ->description($description)
            ->columnSpanFull()
            ->schema([
                TextEntry::make('impact')
                    ->label(__('Affected by an outage'))
                    ->hiddenLabel()
                    ->state(fn (Model $record): array => $impacts($record)
                        ->map(fn (ImpactedEnvironment $impact): string => match (true) {
                            ! $impact->isDirect() => __(':environment: via :path', ['environment' => $impact->environment->full_name, 'path' => $impact->path()]),
                            $impact->domain !== null && $impact->role === ServerRole::Dns => __(':environment: DNS of :domain on :server', [
                                'environment' => $impact->environment->full_name,
                                'domain' => $impact->domain->name,
                                'server' => $impact->server->name,
                            ]),
                            $impact->domain !== null && $impact->role === ServerRole::Mail => __(':environment: mail of :domain on :server', [
                                'environment' => $impact->environment->full_name,
                                'domain' => $impact->domain->name,
                                'server' => $impact->server->name,
                            ]),
                            default => __(':environment: runs on :server (:role)', [
                                'environment' => $impact->environment->full_name,
                                'server' => $impact->server->name,
                                'role' => $impact->role->getLabel(),
                            ]),
                        })
                        ->all())
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder(__('Nothing.')),
            ]);
    }
}
