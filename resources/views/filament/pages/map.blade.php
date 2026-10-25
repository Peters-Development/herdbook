@php
    use Filament\Support\Facades\FilamentAsset;

    // The texts the map script builds its sentences from (herdbookMap()).
    $text = [
        'environments' => __(':count environment|:count environments'),
        'servers' => __(':count other server|:count other servers'),
        'environmentsAndServers' => __(':environments and :servers'),
        'via' => __('via :path'),
        'runsOnIt' => __('runs on it'),
    ];

    // The server's name and what it hits, in this language's word order.
    $impactSentence = strtr(e(__('If :server goes down, that affects :affected:')), [
        ':server' => '<strong class="fi-font-mono" x-text="impact?.server"></strong>',
        ':affected' => '<span x-text="affected()"></span>',
    ]);
@endphp

<x-filament-panels::page>
    <div class="hb-map-toolbar">
        <label class="hb-map-toggle">
            <x-filament::input.checkbox wire:model.live="productionOnly" />
            <span>{{ __('Production only') }}</span>
        </label>

        <x-filament::input.wrapper class="hb-map-select">
            <x-filament::input.select wire:model.live="projectId" :aria-label="__('Project')">
                <option value="">{{ __('All projects') }}</option>
                @foreach ($projects as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>

        <x-filament::input.wrapper class="hb-map-select">
            <x-filament::input.select wire:model.live="clientId" :aria-label="__('Client')">
                <option value="">{{ __('All clients') }}</option>
                @foreach ($clients as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>

        <p class="hb-map-hint">{{ __('Hover over anything to see what it is connected to. Click a server to see what goes down with it.') }}</p>
    </div>

    {{-- A new filter starts the map afresh; the poll only refreshes what is inside the nodes. --}}
    <div wire:key="map-{{ $filterKey }}" wire:poll.60s>
        <div x-data="{ herdbookMap() {} }">
            {{-- wire:ignore.self: the map script sets classes here, a poll must leave them. --}}
            <div
                x-load
                x-load-src="{{ FilamentAsset::getAlpineComponentSrc('herdbook-map') }}"
                x-data="herdbookMap(@js($text))"
                x-on:keydown.escape.window="if (selected && ! $event.defaultPrevented) clear()"
                class="hb-map"
                wire:ignore.self
            >
                {{-- Floats over the map (no jump), and stays in view while scrolling. --}}
                <div class="hb-map-impact-region" role="status" wire:ignore>
                    <div class="hb-map-impact" x-show="impact || loading" x-cloak>
                        <div class="hb-map-impact-head">
                            <p x-show="loading">{{ __('Working out what goes down…') }}</p>
                            <p x-show="! loading && impact?.error">{{ __('Could not work out what goes down. Try again.') }}</p>
                            <p x-show="! loading && impact?.missing">{{ __('This server no longer exists. Reload the page.') }}</p>
                            <p x-show="! loading && impact && ! impact.missing && ! impact.error">{!! $impactSentence !!}</p>
                            <div class="hb-map-impact-actions">
                                <a x-show="impact?.url" x-bind:href="impact?.url" class="hb-map-impact-link">{{ __('Open server') }}</a>
                                <button type="button" x-on:click="clear()" class="hb-map-impact-link">{{ __('Close') }}</button>
                            </div>
                        </div>
                        <ul class="hb-map-impact-list" x-show="! loading && impact && ! impact.missing && ! impact.error">
                            <template x-for="item in impact?.items ?? []" x-bind:key="item.key">
                                <li>
                                    <a x-bind:href="item.url" x-text="item.label"></a>
                                    <span class="hb-map-impact-path fi-font-mono" x-text="path(item)"></span>
                                </li>
                            </template>
                            <li x-show="impact && impact.items.length === 0 && impact.servers === 0">{{ __('Nothing: no environment runs on it, and no other server depends on it.') }}</li>
                            <li x-show="impact && impact.items.length === 0 && impact.servers > 0">{{ __('No environments, only the servers highlighted on the map.') }}</li>
                        </ul>
                    </div>
                </div>

                <div class="hb-map-scroll">
                    <div class="hb-map-canvas" x-ref="canvas">
                        <svg class="hb-map-lines" x-ref="lines" wire:ignore aria-hidden="true"></svg>

                        <div
                            class="hb-map-columns"
                            x-ref="nodes"
                            data-edges="{{ json_encode($map['edges'], JSON_THROW_ON_ERROR) }}"
                            x-on:mouseover="enter($event)"
                            x-on:mouseout="leave($event)"
                            x-on:focusin="enter($event)"
                            x-on:focusout="leave($event)"
                            x-on:click="click($event)"
                        >
                            @foreach ($columns as $column => $heading)
                                <section class="hb-map-column" data-column="{{ $column }}" aria-label="{{ $heading }}">
                                    <h3 class="hb-map-heading">{{ $heading }}</h3>

                                    @if ($column === 'environments')
                                        @forelse ($map['groups'] as $group)
                                            <div class="hb-map-group" wire:key="{{ $group['id'] }}">
                                                <a href="{{ $group['url'] }}" class="hb-map-group-head">
                                                    <span class="hb-map-group-name">{{ $group['label'] }}</span>
                                                    <span class="hb-node-sub">{{ $group['sub'] }}</span>
                                                </a>
                                                @foreach ($group['nodes'] as $node)
                                                    @include('filament.pages.map-node', ['node' => $node])
                                                @endforeach
                                            </div>
                                        @empty
                                            <p class="hb-map-empty">{{ __('No environments') }}</p>
                                        @endforelse
                                    @else
                                        @forelse ($map['columns'][$column] as $node)
                                            @include('filament.pages.map-node', ['node' => $node])
                                        @empty
                                            <p class="hb-map-empty">{{ __('Nothing') }}</p>
                                        @endforelse
                                    @endif
                                </section>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
