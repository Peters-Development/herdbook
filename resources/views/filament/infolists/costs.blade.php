{{-- "Costs" on a project or client page (CostSchema::ownerSection()). --}}
@php
    $costs = $getState();
@endphp

<div class="hb-costs-owner">
    @if ($costs['items'] === [] && $costs['sharedItems'] === [] && $costs['unpriced'] === [])
        <p class="hb-status-muted">{{ __('Nothing with an amount is linked to :owner yet.', ['owner' => $costs['owner']]) }}</p>
    @else
        <div class="hb-costs-owner-totals">
            <div class="hb-costs-owner-total">
                <span class="hb-status-stat-label">{{ __('Per year') }}</span>
                <span class="hb-costs-owner-value">{{ $costs['yearly'] }}</span>
                @if ($costs['perMonth'])
                    <span class="hb-status-muted">{{ __(':amount per month on average', ['amount' => $costs['perMonth']]) }}</span>
                @endif
            </div>
            @if ($costs['shared'])
                <div class="hb-costs-owner-total">
                    <span class="hb-status-stat-label">{{ __('Shared with others') }}</span>
                    <span class="hb-costs-owner-value">{{ $costs['shared'] }}</span>
                    <span class="hb-status-muted">{{ __('per year, not divided: it counts under ":group"', ['group' => __('Shared')]) }}</span>
                </div>
            @endif
        </div>

        @if ($costs['items'] !== [] || $costs['sharedItems'] !== [])
            <ul class="hb-costs-lines">
                @foreach ($costs['items'] as $line)
                    @include('filament.pages.costs-line', ['line' => $line, 'shared' => false])
                @endforeach
                @if ($costs['sharedItems'] !== [])
                    <li class="hb-costs-shared-label">{{ __('Shared with others') }}</li>
                    @foreach ($costs['sharedItems'] as $line)
                        @include('filament.pages.costs-line', ['line' => $line, 'shared' => true])
                    @endforeach
                @endif
            </ul>
        @endif

        @if ($costs['unpriced'] !== [])
            <p class="hb-costs-unpriced">
                {{ __('No amount yet, so not counted:') }}
                @foreach ($costs['unpriced'] as $item)
                    <a href="{{ $item['url'] }}" @class(['fi-font-mono' => $item['mono']])>{{ $item['name'] }}</a>@if (! $loop->last), @endif
                @endforeach
            </p>
        @endif
    @endif
</div>
