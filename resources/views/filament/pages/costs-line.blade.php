{{-- One line in a group on the cost page or in a cost section (CostSchema::line()). --}}
<li @class(['hb-costs-shared' => $shared])>
    <span class="hb-costs-group-item">
        @include('filament.pages.costs-item', ['line' => $line])
        @if ($line['monthly'])
            <span class="hb-costs-detail">{{ $line['monthly'] }}</span>
        @endif
        @if ($line['detail'])
            <span class="hb-costs-detail">{{ $line['detail'] }}</span>
        @endif
    </span>
    <span class="hb-costs-amount">{{ $line['yearly'] }}</span>
</li>
