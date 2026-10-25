<x-filament-widgets::widget>
    <x-filament::section :heading="__('Expiry calendar')" :description="__('Domains, server contracts, subscriptions and internet contracts that expire in the next 13 weeks.')">
        @if ($expired !== [])
            <div class="hb-cal-expired">
                <span class="hb-cal-expired-label">{{ __('Expired') }}</span>
                @foreach ($expired as $item)
                    <a href="{{ $item['url'] }}" class="hb-cal-chip" style="--hb-tone: var(--{{ $item['color'] }}-500)" title="{{ $item['name'] }} · {{ $item['date'] }} · {{ $item['status'] }}">
                        <x-filament::icon :icon="$item['icon']" class="hb-cal-icon" />
                        <span @class(['fi-font-mono' => $item['mono']])>{{ $item['name'] }}</span>
                        <span class="hb-cal-date">{{ $item['date'] }}</span>
                        <span class="fi-sr-only">{{ $item['status'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        <div class="hb-cal" style="--hb-cal-rows: {{ $rows }}; --hb-cal-marker-width: {{ $width }}%">
            <div class="hb-cal-track">
                <span class="hb-cal-today" title="{{ __('Today') }}"></span>

                @foreach ($markers as $marker)
                    <a
                        href="{{ $marker['url'] }}"
                        @class(['hb-cal-marker', 'hb-cal-marker-flip' => $marker['flip']])
                        style="{{ $marker['flip'] ? 'right: '.(100 - $marker['left']).'%' : 'left: '.$marker['left'].'%' }}; --hb-cal-row: {{ $marker['row'] }}; --hb-tone: var(--{{ $marker['color'] }}-500)"
                        title="{{ $marker['name'] }} · {{ $marker['date'] }} · {{ $marker['status'] }}"
                    >
                        <span class="hb-dot"></span>
                        <x-filament::icon :icon="$marker['icon']" class="hb-cal-icon" />
                        <span @class(['hb-cal-label', 'fi-font-mono' => $marker['mono']])>{{ $marker['name'] }}</span>
                        <span class="fi-sr-only">{{ $marker['date'] }}, {{ $marker['status'] }}</span>
                    </a>
                @endforeach
            </div>

            <div class="hb-cal-axis" aria-hidden="true">
                <span class="hb-cal-tick" style="left: 0">{{ __('Today') }}</span>
                @foreach ($ticks as $tick)
                    <span class="hb-cal-tick" style="left: {{ $tick['left'] }}%">{{ $tick['label'] }}</span>
                @endforeach
            </div>
        </div>

        @if ($markers === [] && $expired === [])
            <p class="hb-cal-empty">{{ __('Nothing expires in the next 13 weeks.') }}</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
