<?php

namespace Tests\Feature\Health;

use App\Enums\TimelineRange;
use App\Services\Health\Timeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class TimelineTest extends TestCase
{
    public function test_positions_are_clamped_to_the_range_and_a_band_near_the_end_grows_to_the_left(): void
    {
        $timeline = $this->timeline([100, null, 300]);

        $this->assertSame(0.0, $timeline->position(Date::parse('2026-10-23 08:00:00')));
        $this->assertSame(50.0, $timeline->position(Date::parse('2026-10-23 11:30:00')));
        $this->assertSame(100.0, $timeline->position(Date::parse('2026-10-24 00:00:00')));
        $this->assertSame(['left' => 50.0, 'width' => 0.0], $timeline->span(Date::parse('2026-10-23 11:30:00'), null));
        $this->assertSame(['style' => 'left: 0; width: 50%', 'flip' => false], $this->rounded($timeline->band(Date::parse('2026-10-23 10:00:00'), Date::parse('2026-10-23 11:30:00'))));
        $this->assertSame(['style' => 'right: 0; width: 10%', 'flip' => true], $this->rounded($timeline->band(Date::parse('2026-10-23 12:42:00'), Date::parse('2026-10-23 13:00:00'))));
    }

    public function test_the_response_time_has_a_round_scale_and_breaks_where_a_bar_has_none(): void
    {
        $timeline = $this->timeline([100, null, 300]);

        $this->assertSame(400, $timeline->scaleMs());
        // A lone point becomes a short dash, or it would draw nothing.
        $this->assertSame(['0.2,75 0.8,75', '2.2,25 2.8,25'], $timeline->responseSegments());
        $this->assertNull($this->timeline([null, null, null])->scaleMs());
        $this->assertSame([], $this->timeline([null, null, null])->responseSegments());
    }

    public function test_a_tick_near_the_right_edge_is_aligned_to_it(): void
    {
        // 24 hours from 01:00 in Amsterdam: a label every three hours, the last at 00:00.
        $ticks = $this->timeline(array_fill(0, 24, 100), firstHour: '2026-10-22 23:00:00')->ticks();

        $this->assertSame(['03:00', '06:00', '09:00', '12:00', '15:00', '18:00', '21:00', '00:00'], array_column($ticks, 'label'));
        $this->assertSame([false, false, false, false, false, false, false, true], array_column($ticks, 'end'));
        $this->assertSame([false, true, false, true, false, true, false, true], array_column($ticks, 'minor'));
    }

    public function test_day_ticks_read_in_the_active_language(): void
    {
        // Two days of hours from midnight in Amsterdam (22:00 UTC): a label at each midnight.
        $timeline = fn (): Timeline => $this->timeline(array_fill(0, 48, 100), firstHour: '2026-10-12 22:00:00', range: TimelineRange::Week);

        $this->assertSame(['di 13-10', 'wo 14-10'], array_column($timeline()->ticks(), 'label'));

        app()->setLocale('en');

        $this->assertSame(['Tue 13 Oct', 'Wed 14 Oct'], array_column($timeline()->ticks(), 'label'));
    }

    /**
     * An hour per response time, from 10:00 UTC (12:00 in Amsterdam).
     *
     * @param  list<?int>  $ms
     */
    private function timeline(array $ms, string $firstHour = '2026-10-23 10:00:00', TimelineRange $range = TimelineRange::Day): Timeline
    {
        $first = CarbonImmutable::parse($firstHour, 'UTC');
        $buckets = array_map(fn (int $index): array => [
            'start' => $first->addHours($index),
            'end' => $first->addHours($index + 1),
            'label' => '',
            'share' => 1.0,
            'worst' => 1.0,
            'ms' => $ms[$index],
        ], array_keys($ms));

        return new Timeline($range, $first, $first->addHours(count($ms)), $buckets, 100.0, null, [], collect(), collect(), collect());
    }

    /**
     * @param  array{style: string, flip: bool}  $band
     * @return array{style: string, flip: bool}
     */
    private function rounded(array $band): array
    {
        return ['style' => preg_replace('/(\d+)\.0+%/', '$1%', str_replace(['left: 0%', 'right: 0%'], ['left: 0', 'right: 0'], $band['style'])), 'flip' => $band['flip']];
    }
}
