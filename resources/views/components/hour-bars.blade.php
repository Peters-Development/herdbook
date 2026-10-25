{{-- One bar per clock hour, oldest first: the share of good results of a HealthReport. --}}
@props(['report'])

@php
    $tones = $report->hourTones();
    $timezone = config('herdbook.display_timezone');
    $first = $report->firstHour;
    $percent = fn (?float $value): string => $value === null ? __('no data') : \App\Filament\Support\Percentage::format($value);
@endphp

<div
    {{ $attributes->class('hb-bars') }}
    role="img"
    aria-label="{{ __('Uptime per hour, last :count hours: :uptime', ['count' => count($tones), 'uptime' => $percent($report->uptime)]) }}"
>
    @foreach ($tones as $index => $tone)
        @php($hour = $first->addHours($index)->timezone($timezone))
        <span
            class="hb-bar hb-tone-{{ $tone }}"
            title="{{ $hour->format('H:i') }}–{{ $hour->addHour()->format('H:i') }}: {{ $percent($report->hours[$index] === null ? null : $report->hours[$index] * 100) }}"
        ></span>
    @endforeach
</div>
