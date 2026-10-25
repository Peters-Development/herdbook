<?php

namespace App\Filament\Resources\Servers\Schemas;

use App\Enums\MaintenanceTask;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\Networks\NetworkResource;
use App\Filament\Support\CostSchema;
use App\Filament\Support\ImpactSchema;
use App\Filament\Support\MaintenanceSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\EnvironmentServer;
use App\Models\Server;
use App\Models\ServerDependency;
use App\Services\Impact\ImpactAnalyzer;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class ServerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make(__('Server'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('status')->label(__('Status'))->badge(),
                        TextEntry::make('provider.name')->label(__('Provider'))->placeholder('—'),
                        TextEntry::make('provider_hostname')->label(__('Name at the provider'))->placeholder('—'),
                        TextEntry::make('plan')->label(__('Plan'))->placeholder('—'),
                        TextEntry::make('location')->label(__('Location'))->placeholder('—'),
                        TextEntry::make('os')
                            ->label(__('Operating system'))
                            ->state(fn (Server $record): ?string => trim("{$record->os_name} {$record->os_version}") ?: null)
                            ->placeholder('—'),
                    ]),
                Section::make(__('Network and access'))
                    ->columns(3)
                    ->schema([
                        // Optional: a VPS has its provider.
                        TextEntry::make('network.name')
                            ->label(__('Network'))
                            ->url(fn (Server $record): ?string => $record->network_id ? NetworkResource::getUrl('view', ['record' => $record->network_id]) : null)
                            ->visible(fn (Server $record): bool => $record->network_id !== null)
                            ->columnSpanFull(),
                        TextEntry::make('public_ipv4')->label(__('Public IPv4'))->fontFamily(FontFamily::Mono)->copyable()->placeholder('—'),
                        TextEntry::make('public_ipv6')->label(__('Public IPv6'))->fontFamily(FontFamily::Mono)->copyable()->placeholder('—'),
                        TextEntry::make('private_ip')->label(__('Private IP'))->fontFamily(FontFamily::Mono)->copyable()->placeholder('—'),
                        TextEntry::make('tailscale_ip')->label(__('Tailscale IP'))->fontFamily(FontFamily::Mono)->copyable()->placeholder('—'),
                        TextEntry::make('ssh')
                            ->label('SSH')
                            ->fontFamily(FontFamily::Mono)
                            ->state(fn (Server $record): ?string => self::sshCommand($record))
                            ->copyable()
                            ->placeholder('—')
                            ->columnSpan(2),
                    ]),
                Section::make(__('Runs here'))
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('assignments')
                            ->label(__('Runs here'))
                            ->hiddenLabel()
                            ->state(fn (Server $record): array => $record->assignments()
                                ->with('environment.project')
                                ->get()
                                ->map(fn (EnvironmentServer $assignment): string => "{$assignment->environment->full_name} ({$assignment->role->getLabel()})")
                                ->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder(__('Nothing runs on this server.')),
                    ]),
                ImpactSchema::section(
                    __('What goes down when this server fails, including through servers that depend on it.'),
                    fn (Server $record) => app(ImpactAnalyzer::class)->forServer($record),
                ),
                Section::make(__('Dependencies'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('dependencies')
                            ->label(__('Depends on'))
                            ->state(fn (Server $record): array => $record->dependencies()->with('dependsOn')->get()
                                ->map(fn (ServerDependency $dependency): string => "{$dependency->dependsOn->name} ({$dependency->kind})")
                                ->all())
                            ->listWithLineBreaks()
                            ->placeholder(__('Nothing.')),
                        TextEntry::make('dependents')
                            ->label(__('Needed by'))
                            ->state(fn (Server $record): array => $record->dependents()->with('server')->get()
                                ->map(fn (ServerDependency $dependency): string => "{$dependency->server->name} ({$dependency->kind})")
                                ->all())
                            ->listWithLineBreaks()
                            ->placeholder(__('Nothing.')),
                    ]),
                Section::make(__('Maintenance'))
                    ->description(__('When it was last done, according to the logged maintenance.'))
                    ->columns(5)
                    ->columnSpanFull()
                    ->schema([
                        ...array_map(fn (MaintenanceTask $task): TextEntry => TextEntry::make("last_{$task->value}")
                            ->label($task->getLabel())
                            ->state(fn (Server $record): ?string => self::ago($record->lastDone()[$task->value]))
                            ->placeholder(__('Not yet')), MaintenanceTask::tracked()),
                        TextEntry::make('next_maintenance')
                            ->label(__('Planned'))
                            ->state(fn (Server $record): ?CarbonImmutable => $record->nextMaintenance()?->starts_at)
                            ->dateTime(__('formats.datetime'))
                            ->badge()
                            ->color(fn (Server $record): ?string => ($next = $record->nextMaintenance()) ? MaintenanceSchema::statusColor($next) : null)
                            ->helperText(fn (Server $record): ?string => ($next = $record->nextMaintenance())
                                ? ($next->isOverdue() ? MaintenanceSchema::statusLabel($next).' · ' : '').$next->title
                                : null)
                            ->url(fn (Server $record): ?string => ($next = $record->nextMaintenance()) ? MaintenanceResource::getUrl('view', ['record' => $next]) : null)
                            ->placeholder(__('Nothing')),
                    ]),
                Section::make(__('Specifications and costs'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('vcpu')->label('vCPU')->placeholder('—'),
                        TextEntry::make('ram_gb')->label('RAM')->suffix(' GB')->placeholder('—'),
                        TextEntry::make('disk_gb')->label(__('Storage'))->suffix(' GB')->placeholder('—'),
                        TextEntry::make('backup_policy')->label(__('Backups'))->placeholder('—'),
                        CostSchema::entry(),
                    ]),
                RenewalSchema::infolistSection(__('Contract')),
                Section::make(__('Notes'))
                    ->collapsible()
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('notes')->label(__('Notes'))->hiddenLabel()->markdown()->placeholder(__('No notes.')),
                    ]),
            ]);
    }

    /**
     * "12 Oct 2026 (2 weeks ago)".
     */
    private static function ago(?CarbonImmutable $moment): ?string
    {
        return $moment === null ? null : $moment->timezone(config('herdbook.display_timezone'))->translatedFormat(__('formats.date')).' ('.$moment->diffForHumans().')';
    }

    private static function sshCommand(Server $record): ?string
    {
        // The private address last: on a VPS that is WireGuard, which only its
        // peers reach; on a server in a local network it is the only one there is.
        $host = $record->tailscale_ip ?? $record->public_ipv4 ?? $record->public_ipv6 ?? $record->private_ip;

        if ($host === null) {
            return null;
        }

        $target = $record->ssh_user ? "{$record->ssh_user}@{$host}" : $host;

        return $record->ssh_port === 22 ? "ssh {$target}" : "ssh -p {$record->ssh_port} {$target}";
    }
}
