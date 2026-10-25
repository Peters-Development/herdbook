@php
    use App\Filament\Support\HealthText;
    use App\Filament\Support\Percentage;
@endphp

<x-filament-widgets::widget wire:poll.30s>
    <x-filament::section :heading="__('Projects')" :description="__('How production is doing, with what needs attention first.')">
        <x-slot name="afterHeader">
            <x-filament::link :href="\App\Filament\Pages\Map::getUrl()" icon="heroicon-m-map">{{ __('On the map') }}</x-filament::link>
        </x-slot>

        @if ($tiles === [])
            <x-filament::empty-state :heading="$unmonitored > 0 ? __('No monitored projects') : __('No projects yet')" icon="heroicon-o-rectangle-stack" />
        @else
            <div class="hb-board">
                @foreach ($tiles as $tile)
                    @php
                        $health = $tile['health'];
                        $problem = $health->problems->first();
                    @endphp

                    <a href="{{ $tile['url'] }}" class="hb-tile hb-state-{{ $health->state->value }}" wire:key="project-tile-{{ $tile['project']->id }}">
                        <div class="hb-tile-head">
                            <span class="hb-tile-name">{{ $tile['project']->name }}</span>
                            <x-filament::badge :color="$health->state->getColor()" size="sm">
                                {{ $health->state->getLabel() }}
                            </x-filament::badge>
                        </div>

                        <div class="hb-tile-sub">{{ $tile['project']->client?->name ?? __('Own project') }}</div>

                        @if ($problem)
                            <div class="hb-tile-problem">
                                <span title="{{ HealthText::problem($problem) }}">{{ HealthText::problem($problem) }}</span>
                                @if ($since = HealthText::since($health))
                                    <span class="hb-tile-since">{{ $since }}</span>
                                @endif
                            </div>
                        @endif

                        @if ($tile['environments'] !== [])
                            <div class="hb-tile-envs">
                                @foreach ($tile['environments'] as $environment)
                                    <span class="hb-env" title="{{ $environment['name'] }}: {{ $environment['state']->getLabel() }}">
                                        <span class="hb-dot hb-state-{{ $environment['state']->value }}"></span>
                                        {{ $environment['name'] }}
                                        <span class="fi-sr-only">: {{ $environment['state']->getLabel() }}</span>
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        @if ($tile['servers'] !== [] || $tile['upstream'] !== [])
                            <div class="hb-tile-servers fi-font-mono" title="{{ $tile['upstream'] === []
                                ? __('Runs on :servers', ['servers' => implode(', ', $tile['servers']) ?: '—'])
                                : __('Runs on :servers; through those servers it depends on :upstream', ['servers' => implode(', ', $tile['servers']) ?: '—', 'upstream' => implode(', ', $tile['upstream'])]) }}">
                                {{ implode(' · ', $tile['servers']) }}@if ($tile['upstream'] !== [])<span class="hb-tile-upstream"> · {{ __('via :servers', ['servers' => implode(' · ', $tile['upstream'])]) }}</span>@endif
                            </div>
                        @endif

                        @if ($health->uptime !== null)
                            <x-hour-bars :report="$health" class="hb-tile-bars" />
                        @endif

                        <div class="hb-tile-foot">
                            @if ($health->uptime !== null)
                                <span>{{ __(':percent in 24 hours', ['percent' => Percentage::format($health->uptime)]) }}</span>
                            @endif
                            @if ($health->responseMs !== null)
                                <span>{{ $health->responseMs }} ms</span>
                            @endif
                            @if ($tile['open'] > 0)
                                <span class="hb-tile-incidents">{{ trans_choice(':count open incident|:count open incidents', $tile['open']) }}</span>
                            @endif
                            @if ($tile['monitoring'] > 0)
                                <span>{{ trans_choice(':count incident being watched|:count incidents being watched', $tile['monitoring']) }}</span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
