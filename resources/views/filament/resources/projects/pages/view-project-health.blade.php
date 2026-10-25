@php
    use App\Enums\TimelineRange;
    use App\Filament\Resources\Checks\CheckResource;
    use App\Filament\Resources\Maintenances\MaintenanceResource;
    use App\Filament\Support\HealthText;
    use App\Filament\Support\LocalTime;
    use App\Filament\Support\MaintenanceSchema;
    use App\Filament\Support\Percentage;
    use App\Services\Health\HealthReport;

    $number = fn (float $value): string => Percentage::format($value);
    $percent = fn (?float $value): string => $value === null ? '—' : $number($value);
    $share = fn (?float $value): string => $value === null ? __('no data') : $number($value * 100);
    $period = __('past :range', ['range' => $current->getLabel()]);
    $daily = $current->daily();
    $availability = match (true) {
        $timeline->lanes === [] => __('There are no checks on production: only maintenance and incidents.'),
        $daily => __('Each bar shows the worst check of that day, colored by its worst hour. Click a bar for what happened then.'),
        default => __('Each bar shows the worst check of that hour. Click a bar for what happened then.'),
    };
@endphp

<x-filament-panels::page>
    {{-- Only the day refreshes itself: the longer ranges barely move in a minute. --}}
    <div class="hb-status" @if ($current === TimelineRange::Day) wire:poll.60s @endif>
        <x-filament::tabs :label="__('Period')" contained>
            @foreach ($ranges as $option)
                <x-filament::tabs.item :active="$option === $current" wire:click="$set('range', '{{ $option->value }}')">
                    {{ $option->getLabel() }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <div class="hb-status-summary">
            <div class="hb-status-stat">
                <span class="hb-status-stat-label">{{ __('Now') }}</span>
                <x-filament::badge :color="$health->state->getColor()">{{ $health->state->getLabel() }}</x-filament::badge>
                @if ($problem = $health->problems->first())
                    <span class="hb-status-stat-sub" title="{{ HealthText::problem($problem) }}">{{ HealthText::problem($problem) }}</span>
                @endif
                @if ($since = HealthText::since($health))
                    <span class="hb-status-stat-sub">{{ $since }}</span>
                @endif
            </div>
            <div class="hb-status-stat">
                <span class="hb-status-stat-label">{{ __('Uptime') }}</span>
                <span class="hb-status-stat-value">{{ $percent($timeline->uptime) }}</span>
                <span class="hb-status-stat-sub">{{ $period }}</span>
            </div>
            <div class="hb-status-stat">
                <span class="hb-status-stat-label">{{ __('Response time') }}</span>
                <span class="hb-status-stat-value">{{ $timeline->responseMs === null ? '—' : $timeline->responseMs.' ms' }}</span>
                <span class="hb-status-stat-sub">{{ __('average, :period', ['period' => $period]) }}</span>
            </div>
            <div class="hb-status-stat">
                <span class="hb-status-stat-label">{{ __('Open incidents') }}</span>
                <span @class(['hb-status-stat-value', 'hb-status-stat-bad' => $openIncidents > 0])>{{ $openIncidents }}</span>
                <span class="hb-status-stat-sub">{{ trans_choice(':count incident, :period|:count incidents, :period', $timeline->incidents->count(), ['period' => $period]) }}</span>
            </div>
            @if ($next)
                <a class="hb-status-stat hb-status-stat-link" href="{{ MaintenanceResource::getUrl('view', ['record' => $next]) }}">
                    <span class="hb-status-stat-label">{{ __('Planned maintenance') }}</span>
                    <x-filament::badge :color="MaintenanceSchema::statusColor($next)">{{ LocalTime::format($next->starts_at, withDay: true) }}</x-filament::badge>
                    <span class="hb-status-stat-sub">{{ $next->isOverdue() ? __('Overdue').' · ' : '' }}{{ $next->title }}</span>
                </a>
            @else
                <div class="hb-status-stat">
                    <span class="hb-status-stat-label">{{ __('Planned maintenance') }}</span>
                    <span class="hb-status-stat-value">—</span>
                    <span class="hb-status-stat-sub">{{ __('Nothing planned') }}</span>
                </div>
            @endif
        </div>

        <x-filament::section :heading="__('Availability')" :description="$availability">
            <div class="hb-timeline">
                @foreach ([__('Maintenance') => $maintenanceBands, __('Incidents') => $incidentBands] as $track => $stack)
                    @if ($stack['bands'] !== [])
                        <div class="hb-timeline-track" role="group" aria-label="{{ $track }}" style="--hb-rows: {{ $stack['rows'] }}">
                            @foreach ($stack['bands'] as $band)
                                <a
                                    href="{{ $band['url'] }}"
                                    @class(['hb-band', 'hb-band-flip' => $band['flip'], 'hb-band-planned' => $band['planned']])
                                    style="{{ $band['style'] }}; --hb-row: {{ $band['row'] }}; --hb-tone: var(--{{ $band['tone'] }}-500)"
                                    title="{{ $band['title'] }}"
                                >
                                    <x-filament::icon :icon="$band['icon']" class="hb-band-icon" />
                                    <span class="hb-band-label" aria-hidden="true">{{ $band['label'] }}</span>
                                    <span class="fi-sr-only">{{ $band['title'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endforeach

                @if ($timeline->lanes !== [])
                    <div
                        @class(['hb-timeline-bars', 'hb-timeline-dense' => count($timeline->buckets) > 90])
                        role="group"
                        aria-label="{{ $daily
                            ? __('Uptime per day, :period: :uptime. Arrow keys: previous or next day.', ['period' => $period, 'uptime' => $percent($timeline->uptime)])
                            : __('Uptime per hour, :period: :uptime. Arrow keys: previous or next hour.', ['period' => $period, 'uptime' => $percent($timeline->uptime)]) }}"
                        {{-- One bar in the tab order, and it follows the arrow keys. --}}
                        x-data="{ move(bar) { if (! bar) return; for (const other of $el.children) other.tabIndex = -1; bar.tabIndex = 0; bar.focus() } }"
                        x-on:keydown.right.exact.prevent="move($event.target.nextElementSibling)"
                        x-on:keydown.left.exact.prevent="move($event.target.previousElementSibling)"
                        x-on:keydown.home.exact.prevent="move($el.firstElementChild)"
                        x-on:keydown.end.exact.prevent="move($el.lastElementChild)"
                    >
                        @foreach ($timeline->buckets as $index => $item)
                            <button
                                type="button"
                                wire:click="select({{ $item['start']->getTimestamp() }})"
                                wire:key="bar-{{ $item['start']->getTimestamp() }}"
                                @class(['hb-timeline-bar', 'hb-tone-'.HealthReport::tone($item['worst']), 'hb-timeline-bar-selected' => $bucket === $index])
                                tabindex="{{ $index === $focus ? 0 : -1 }}"
                                title="{{ $item['label'] }}: {{ $share($item['share']) }}"
                                aria-pressed="{{ $bucket === $index ? 'true' : 'false' }}"
                            ><span class="fi-sr-only">{{ $item['label'] }}: {{ $share($item['share']) }}</span></button>
                        @endforeach
                    </div>
                @endif

                <div @class(['hb-timeline-axis', 'hb-timeline-axis-bare' => $timeline->lanes === []]) aria-hidden="true">
                    @foreach ($timeline->ticks() as $tick)
                        <span @class(['hb-timeline-tick', 'hb-timeline-tick-end' => $tick['end'], 'hb-timeline-tick-minor' => $tick['minor']]) style="left: {{ $tick['left'] }}%">{{ $tick['label'] }}</span>
                    @endforeach
                </div>
            </div>

            <div class="hb-timeline-legend" aria-hidden="true">
                @if ($timeline->lanes !== [])
                    <span><span class="hb-dot hb-tone-ok"></span> {{ __('Healthy') }}</span>
                    <span><span class="hb-dot hb-tone-warn"></span> {{ __('Sometimes failed') }}</span>
                    <span><span class="hb-dot hb-tone-bad"></span> {{ __('Mostly failed') }}</span>
                    <span><span class="hb-dot hb-tone-none"></span> {{ __('No data') }}</span>
                @endif
                @if ($maintenanceBands['bands'] !== [])
                    <span><span class="hb-legend-band" style="--hb-tone: var(--info-500)"></span> {{ __('Maintenance') }}</span>
                    <span><span class="hb-legend-band hb-band-planned" style="--hb-tone: var(--info-500)"></span> {{ __('Planned') }}</span>
                    <span><span class="hb-legend-band hb-band-planned" style="--hb-tone: var(--warning-500)"></span> {{ __('Overdue') }}</span>
                @endif
                @if ($incidentBands['bands'] !== [])
                    <span><span class="hb-legend-band" style="--hb-tone: var(--danger-500)"></span> {{ __('Incident') }}</span>
                @endif
            </div>
        </x-filament::section>

        @if ($bucket !== null)
            <x-filament::section :heading="$timeline->label($bucket)" :description="__(':share succeeded', ['share' => $share($timeline->buckets[$bucket]['share'])])" class="hb-status-detail">
                <x-slot name="afterHeader">
                    <x-filament::link tag="button" wire:click="select(null)" icon="heroicon-m-x-mark">{{ __('Close') }}</x-filament::link>
                </x-slot>

                @forelse ($failures as $failure)
                    <div class="hb-failure">
                        <div class="hb-failure-head">
                            <a href="{{ CheckResource::getUrl('view', ['record' => $failure['lane']->check]) }}" class="hb-failure-name fi-font-mono">{{ $failure['lane']->label() }}</a>
                            <span class="hb-failure-count">{{ __(':failed of :total failed', ['failed' => $failure['down'], 'total' => $failure['up'] + $failure['down']]) }}</span>
                        </div>
                        @if ($failure['errors'] !== [])
                            <ul class="hb-failure-errors">
                                @foreach ($failure['errors'] as $error)
                                    <li><span class="hb-failure-times">{{ $error['count'] }}×</span> {{ $error['error'] }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="hb-status-muted">{{ __('Error messages are kept for :days days; for this period only the counts remain.', ['days' => config('herdbook.checks.keep_results_days')]) }}</p>
                        @endif
                    </div>
                @empty
                    <p class="hb-status-muted">{{ $daily ? __('No failed checks on this day.') : __('No failed checks in this hour.') }}</p>
                @endforelse
            </x-filament::section>
        @endif

        @if ($timeline->scaleMs() !== null)
            <x-filament::section :heading="__('Response time')" :description="$daily ? __('Average per day, for the websites (HTTP checks).') : __('Average per hour, for the websites (HTTP checks).')">
                <div class="hb-rt">
                    <span class="hb-rt-scale">{{ $timeline->scaleMs() }} ms</span>
                    <svg class="hb-rt-chart" viewBox="0 0 {{ count($timeline->buckets) }} 100" preserveAspectRatio="none" role="img" aria-label="{{ __('Response time, :period, average :ms ms', ['period' => $period, 'ms' => $timeline->responseMs]) }}">
                        @foreach ($timeline->responseSegments() as $points)
                            <polyline points="{{ $points }}" class="hb-rt-line" vector-effect="non-scaling-stroke" />
                        @endforeach
                        @foreach ($timeline->buckets as $index => $item)
                            @if ($item['ms'] !== null)
                                <rect x="{{ $index }}" y="0" width="1" height="100" class="hb-rt-hit"><title>{{ $item['label'] }}: {{ $item['ms'] }} ms</title></rect>
                            @endif
                        @endforeach
                    </svg>
                    <span class="hb-rt-scale">0 ms</span>
                </div>
            </x-filament::section>
        @endif

        @if ($timeline->lanes !== [])
            <x-filament::section :heading="__('Per check')" :description="__('Production hostnames and servers, including the servers they depend on.')">
                <div class="hb-lanes">
                    @foreach ($timeline->lanes as $lane)
                        <div @class(['hb-lane', 'hb-lane-paused' => ! $lane->check->enabled]) wire:key="lane-{{ $lane->check->id }}">
                            <div class="hb-lane-head">
                                <span class="hb-dot" data-color="{{ $lane->check->state->getColor() }}" style="--hb-tone: var(--{{ $lane->check->state->getColor() }}-500)" aria-hidden="true"></span>
                                <span class="fi-sr-only">{{ $lane->check->state->getLabel() }}:</span>
                                <a href="{{ CheckResource::getUrl('view', ['record' => $lane->check]) }}" class="hb-lane-name fi-font-mono" title="{{ $lane->label() }}">{{ $lane->label() }}</a>
                                <span class="hb-lane-type">{{ $lane->check->enabled ? $lane->check->type->getLabel() : __('Paused') }}</span>
                            </div>
                            <div @class(['hb-bars', 'hb-lane-bars', 'hb-timeline-dense' => count($timeline->buckets) > 90]) role="img" aria-label="{{ $lane->label() }}, {{ $period }}: {{ $percent($lane->uptime()) }}">
                                @foreach ($timeline->buckets as $index => $item)
                                    <span class="hb-bar hb-tone-{{ HealthReport::tone($lane->worst[$index]) }}" title="{{ $item['label'] }}: {{ $share($lane->share($index)) }}"></span>
                                @endforeach
                            </div>
                            <span class="hb-lane-uptime">{{ $percent($lane->uptime()) }}</span>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <x-filament::section :heading="$bucket === null ? __('What happened') : __('What happened: :period', ['period' => $timeline->label($bucket)])">
            @if ($feed === [])
                <p class="hb-status-muted">{{ __('No outages, maintenance or incidents in this period.') }}</p>
            @else
                <ol class="hb-feed">
                    @foreach ($feed as $item)
                        <li class="hb-feed-item hb-feed-{{ $item['kind'] }}">
                            <span class="hb-feed-when">{{ LocalTime::format($item['at'], withDay: true) }}</span>
                            <x-filament::icon
                                :icon="match ($item['kind']) { 'down' => 'heroicon-m-arrow-down-circle', 'up' => 'heroicon-m-arrow-up-circle', 'maintenance' => 'heroicon-m-wrench-screwdriver', 'incident' => 'heroicon-m-exclamation-triangle' }"
                                class="hb-feed-icon"
                            />
                            <span class="hb-feed-body">
                                <a href="{{ $item['url'] }}" class="hb-feed-title">{{ $item['title'] }}</a>
                                @if (filled($item['detail']))
                                    <span class="hb-feed-detail">{{ $item['detail'] }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
