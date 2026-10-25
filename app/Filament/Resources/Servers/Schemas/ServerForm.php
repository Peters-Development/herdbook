<?php

namespace App\Filament\Resources\Servers\Schemas;

use App\Enums\CostPeriod;
use App\Enums\ProviderKind;
use App\Enums\ServerStatus;
use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\Server;
use App\Rules\DistinctItems;
use App\Rules\NoSecretMaterial;
use Closure;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class ServerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make(__('Server'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('status')
                            ->label(__('Status'))
                            ->options(ServerStatus::class)
                            ->default(ServerStatus::Active)
                            ->required(),
                        Select::make('provider_id')
                            ->label(__('Provider'))
                            ->relationship('provider', 'name', fn (Builder $query, ?Model $record) => $query->offering(ProviderKind::Hosting, $record?->provider_id))
                            ->searchable()
                            ->preload()
                            ->live(),
                        TextInput::make('provider_hostname')->label(__('Name at the provider'))->maxLength(255),
                        TextInput::make('plan')->label(__('Plan'))->maxLength(255),
                        TextInput::make('location')->label(__('Location'))->maxLength(255),
                    ]),
                Section::make(__('Network and access'))
                    ->columns(2)
                    ->schema([
                        Select::make('network_id')
                            ->label(__('Network'))
                            ->relationship('network', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText(__('Where the server is, such as an office network. A VPS only needs its provider.'))
                            ->columnSpanFull(),
                        TextInput::make('public_ipv4')->label(__('Public IPv4'))->ipv4()->extraInputAttributes(['class' => 'fi-font-mono']),
                        TextInput::make('public_ipv6')->label(__('Public IPv6'))->ipv6()->extraInputAttributes(['class' => 'fi-font-mono']),
                        TextInput::make('private_ip')->label(__('Private IP (WireGuard/LAN)'))->ip()->extraInputAttributes(['class' => 'fi-font-mono']),
                        TextInput::make('tailscale_ip')->label(__('Tailscale IP'))->ip()->extraInputAttributes(['class' => 'fi-font-mono']),
                        TextInput::make('ssh_user')->validationAttribute(__('SSH user'))->label(__('SSH user'))->maxLength(255),
                        TextInput::make('ssh_port')->validationAttribute(__('SSH port'))->label(__('SSH port'))->integer()->minValue(1)->maxValue(65535)->default(22)->required(),
                    ]),
                Section::make(__('Specifications'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('vcpu')->validationAttribute('vCPU')->label('vCPU')->integer()->minValue(1)->maxValue(255),
                        TextInput::make('ram_gb')->validationAttribute('RAM')->label('RAM')->integer()->minValue(1)->maxValue(65535)->suffix('GB'),
                        TextInput::make('disk_gb')->label(__('Storage'))->integer()->minValue(1)->maxValue(4294967295)->suffix('GB'),
                        TextInput::make('os_name')->label(__('Operating system'))->placeholder('Ubuntu')->maxLength(255),
                        TextInput::make('os_version')->label(__('Version'))->placeholder('24.04')->maxLength(20),
                        TextInput::make('backup_policy')->label(__('Backups'))->placeholder(__('Daily snapshot at 02:00'))->maxLength(255),
                    ]),
                CostSchema::formSection(CostPeriod::Month),
                // A machine you own has no contract (Server::hasContract()). The
                // stored record counts too: emptying the date must still be saved.
                RenewalSchema::formSection(__('Contract'))
                    ->visible(fn (Get $get, ?Server $record): bool => filled($get('provider_id')) || filled($get('expires_on')) || (bool) $record?->hasContract()),
                Section::make(__('Depends on'))
                    ->description(__('Servers this server needs, such as storage over NFS or a WireGuard tunnel.'))
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('dependencies')
                            ->label(__('Dependencies'))
                            ->hiddenLabel()
                            ->relationship()
                            ->schema([
                                Select::make('depends_on_id')
                                    ->label(__('Server'))
                                    // A server cannot depend on itself. Inside the repeater,
                                    // $record is the dependency row; the server is the page's.
                                    ->relationship('dependsOn', 'name', fn (Builder $query, Component $livewire) => $query->when(
                                        $livewire instanceof EditRecord,
                                        fn (Builder $query) => $query->whereKeyNot($livewire->getRecord()->getKey()),
                                    ))
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->rules([fn (Component $livewire): Closure => function (string $attribute, mixed $value, Closure $fail) use ($livewire): void {
                                        if ($livewire instanceof EditRecord && (int) $value === $livewire->getRecord()->getKey()) {
                                            $fail(__('A server cannot depend on itself.'));
                                        }
                                    }]),
                                TextInput::make('kind')
                                    ->label(__('Via'))
                                    ->datalist(['NFS', 'WireGuard', 'Database', 'Redis', 'SMTP'])
                                    ->required()
                                    ->maxLength(50),
                                TextInput::make('notes')->label(__('Note'))->maxLength(255),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel(__('Add dependency'))
                            ->rules([new DistinctItems(['depends_on_id', 'kind'], __('The same dependency is in the list twice.'))]),
                    ]),
                Section::make(__('Notes'))
                    ->collapsible()
                    ->columnSpanFull()
                    ->schema([
                        MarkdownEditor::make('notes')->label(__('Notes'))->hiddenLabel()->rules([new NoSecretMaterial]),
                    ]),
            ]);
    }
}
