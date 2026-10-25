@php
    use App\Services\Costs\Money;
@endphp

<x-filament-panels::page>
    <div class="hb-costs">
        <div class="hb-status-summary">
            <div class="hb-status-stat">
                <span class="hb-status-stat-label">{{ __('Per year') }}</span>
                <span class="hb-status-stat-value">{{ $yearly['value'] }}</span>
                <span class="hb-status-muted">{{ $yearly['sub'] }}</span>
            </div>
            @foreach ($tiles as $tile)
                <button
                    type="button"
                    @class(['hb-status-stat', 'hb-status-stat-link', 'hb-costs-tile', 'hb-costs-tile-active' => $tile['active']])
                    wire:click="select('{{ $tile['range'] }}')"
                    aria-pressed="{{ $tile['active'] ? 'true' : 'false' }}"
                >
                    <span class="hb-status-stat-label">{{ $tile['label'] }}</span>
                    <span class="hb-status-stat-value">{{ $tile['value'] }}</span>
                    <span class="hb-status-muted">{{ $tile['sub'] }}</span>
                </button>
            @endforeach
        </div>

        @if ($todo !== [])
            <x-filament::section :heading="__('To sort out')" :description="__('Until these are sorted out, the numbers are not quite right.')" collapsible>
                <ul class="hb-costs-todo">
                    @foreach ($todo as $line)
                        <li>
                            <span class="hb-dot" data-color="{{ $line['color'] }}" style="--hb-tone: var(--{{ $line['color'] }}-500)" aria-hidden="true"></span>
                            @if ($line['url'])
                                <a href="{{ $line['url'] }}">{{ $line['text'] }}</a>
                            @else
                                {{ $line['text'] }}
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('Next 12 months')" :description="__('What is paid each month. Click a month, or an amount above, for its payments.')">
            <div class="hb-costs-chart-wrap">
                <div class="hb-costs-chart" role="group" aria-label="{{ __('Costs per month') }}">
                    @foreach ($bars as $bar)
                        <button
                            type="button"
                            @class(['hb-costs-month', 'hb-costs-month-selected' => $bar['selected']])
                            wire:click="select('{{ $bar['key'] }}')"
                            aria-pressed="{{ $bar['selected'] ? 'true' : 'false' }}"
                            aria-label="{{ $bar['title'] }}"
                            title="{{ $bar['title'] }}"
                        >
                            <span class="hb-costs-month-total">{{ $bar['total'] }}</span>
                            <span class="hb-costs-bar">
                                @foreach ($bar['segments'] as $segment)
                                    <span class="hb-costs-segment hb-costs-{{ $segment['tone'] }}" style="height: {{ $segment['height'] }}%"></span>
                                @endforeach
                            </span>
                            <span class="hb-costs-month-label">{{ $bar['label'] }}<span class="hb-costs-month-year">{{ $bar['year'] }}</span></span>
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="hb-costs-legend" aria-hidden="true">
                <span><span class="hb-costs-swatch hb-costs-monthly"></span>{{ __('Monthly') }}</span>
                <span><span class="hb-costs-swatch hb-costs-renewals"></span>{{ __('Renewals') }}</span>
                <span><span class="hb-costs-swatch hb-costs-decide"></span>{{ __('To decide') }}</span>
            </div>
        </x-filament::section>

        <x-filament::section :heading="$heading" :description="__('Total :amount. What is already paid is not included: a renewal you log moves on to the next term.', ['amount' => Money::format($total)])">
            @if ($renewals === [] && $monthly === [])
                <p class="hb-status-muted">{{ __('Nothing to pay in this period.') }}</p>
            @endif

            @if ($renewals !== [])
                <div class="hb-costs-table-wrap">
                    <table class="hb-costs-table">
                        <caption>{{ __('Renewals') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Date') }}</th>
                                <th scope="col">{{ __('Name') }}</th>
                                <th scope="col">{{ __('Term') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col" class="hb-costs-amount">{{ __('Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($renewals as $line)
                                <tr>
                                    <td class="hb-costs-date">{{ $line['date'] }}</td>
                                    <td>@include('filament.pages.costs-item', ['line' => $line])</td>
                                    <td>{{ $line['term'] }}</td>
                                    <td>
                                        @if ($line['badge'])
                                            <x-filament::badge :color="$line['badge']['color']">{{ $line['badge']['label'] }}</x-filament::badge>
                                        @endif
                                    </td>
                                    <td class="hb-costs-amount">{{ $line['amount'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($monthly !== [])
                <div class="hb-costs-table-wrap">
                    <table class="hb-costs-table">
                        <caption>{{ __('Monthly') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Name') }}</th>
                                <th scope="col">{{ __('Per month') }}</th>
                                @if ($spansMonths)
                                    <th scope="col">{{ __('Months') }}</th>
                                @endif
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col" class="hb-costs-amount">{{ __('Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($monthly as $line)
                                <tr>
                                    <td>@include('filament.pages.costs-item', ['line' => $line])</td>
                                    <td>{{ $line['perMonth'] }}</td>
                                    @if ($spansMonths)
                                        <td>{{ $line['months'] }}</td>
                                    @endif
                                    <td>
                                        @if ($line['badge'])
                                            <x-filament::badge :color="$line['badge']['color']">{{ $line['badge']['label'] }}</x-filament::badge>
                                        @endif
                                    </td>
                                    <td class="hb-costs-amount">
                                        {{ $line['amount'] }}
                                        @if ($line['decide'])
                                            <span class="hb-costs-detail">{{ $line['decide'] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <div class="hb-costs-groups">
            @foreach ($groups as $title => $list)
                <x-filament::section :heading="$title" :description="__('Per year; cancelled items are not counted.')">
                    @if ($list === [])
                        <p class="hb-status-muted">{{ __('No amounts yet.') }}</p>
                    @endif

                    <div class="hb-costs-group-list">
                        @foreach ($list as $group)
                            {{-- wire:ignore.self: picking a period re-renders the page; an open group stays open. --}}
                            <details class="hb-costs-group" wire:key="{{ $title }}-{{ $group['label'] }}-{{ $loop->index }}" wire:ignore.self>
                                <summary>
                                    <span class="hb-costs-group-label">
                                        <x-filament::icon icon="heroicon-m-chevron-right" class="hb-costs-chevron" />
                                        <span @class(['hb-costs-group-name', 'hb-costs-group-bucket' => $group['bucket']])>{{ $group['label'] }}</span>
                                    </span>
                                    <span class="hb-costs-amount">{{ $group['total'] }}</span>
                                    <span class="hb-costs-share" aria-hidden="true"><span style="width: {{ $group['share'] }}%"></span></span>
                                    @if ($group['shared'])
                                        <span class="hb-costs-group-sub">{{ $group['shared'] }}</span>
                                    @endif
                                </summary>
                                <ul class="hb-costs-lines">
                                    @foreach ($group['items'] as $line)
                                        @include('filament.pages.costs-line', ['line' => $line, 'shared' => false])
                                    @endforeach
                                    @if ($group['sharedItems'] !== [])
                                        <li class="hb-costs-shared-label">{{ __('Shared with others, counted under ":group"', ['group' => __('Shared')]) }}</li>
                                        @foreach ($group['sharedItems'] as $line)
                                            @include('filament.pages.costs-line', ['line' => $line, 'shared' => true])
                                        @endforeach
                                    @endif
                                </ul>
                                @if ($group['url'])
                                    <a class="hb-costs-group-link" href="{{ $group['url'] }}">{{ __('Go to :name', ['name' => $group['label']]) }}</a>
                                @endif
                            </details>
                        @endforeach
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
