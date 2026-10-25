{{-- One item on the cost page or in a cost section (CostSchema::describe()): its icon, name and provider. --}}
<a href="{{ $line['url'] }}" class="hb-costs-item">
    <x-filament::icon :icon="$line['icon']" class="hb-costs-icon" />
    <span @class(['fi-font-mono' => $line['mono']])>{{ $line['name'] }}</span>
</a>
@if ($line['provider'])
    <span class="hb-costs-provider">{{ $line['provider'] }}</span>
@endif
