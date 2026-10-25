{{--
    One node on the map. wire:ignore.self: a refresh updates what is inside
    (status, text), not the classes the map script puts on the node itself.
--}}
@php
    $tag = $node['selectable'] ? 'button' : 'a';
    $title = $node['label'].($node['status'] ? ' · '.$node['status'] : '').($node['selectable'] ? ' · '.__('click to see what goes down with it') : '');
@endphp

<{{ $tag }}
    @if ($node['selectable']) type="button" @else href="{{ $node['url'] }}" @endif
    class="hb-node"
    data-node="{{ $node['id'] }}"
    wire:key="{{ $node['id'] }}"
    wire:ignore.self
>
    <span class="hb-node-body" title="{{ $title }}">
        @if ($node['color'])
            <span @class(['hb-dot', 'hb-dot-pulse' => $node['down']]) data-color="{{ $node['color'] }}" style="--hb-tone: var(--{{ $node['color'] }}-500)"></span>
        @endif
        <span class="hb-node-text">
            <span @class(['hb-node-label', 'fi-font-mono' => $node['mono']])>{{ $node['label'] }}</span>
            @if (filled($node['sub']))
                <span @class(['hb-node-sub', 'fi-font-mono' => $node['subMono']])>{{ $node['sub'] }}</span>
            @endif
        </span>
        @if ($node['status'])
            <span class="fi-sr-only">{{ $node['status'] }}</span>
        @endif
    </span>
</{{ $tag }}>
