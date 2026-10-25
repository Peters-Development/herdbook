<?php

namespace App\Filament\Widgets;

use App\Filament\Support\RenewalSchema;
use App\Models\Contracts\Renewable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * What expires in the coming weeks, on a time line: every renewable
 * (Renewable::MODELS) as a marker, stacked where they would overlap. The
 * tables below it are where you renew; this shows when.
 */
class RenewalCalendar extends Widget
{
    public const int DAYS = 91;

    /**
     * The share of the line a marker with its label takes: the next marker
     * in the same row starts after it.
     */
    public const int MARKER_WIDTH = 16;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.renewal-calendar';

    /**
     * @return array{markers: list<array<string, mixed>>, rows: int, width: int, ticks: list<array{left: float, label: string}>, expired: list<array<string, mixed>>}
     */
    protected function getViewData(): array
    {
        $today = now(config('herdbook.display_timezone'))->startOfDay();
        $end = $today->addDays(self::DAYS);

        $items = collect(Renewable::MODELS)
            ->flatMap(fn (string $model): Collection => $this->renewables($model, $end))
            ->sortBy(fn (Renewable $item): string => $item->expires_on->toDateString().$item->name)
            ->values();

        [$expired, $upcoming] = $items->partition(fn (Renewable $item): bool => $item->expires_on->lt($today));

        $rowEnds = [];
        $markers = [];

        foreach ($upcoming as $item) {
            $left = $today->diffInDays($item->expires_on) / self::DAYS * 100;

            // Near the end the label goes to the left of its date.
            $flip = $left > 100 - self::MARKER_WIDTH;
            $start = $flip ? $left - self::MARKER_WIDTH : $left;
            $row = 0;

            while (isset($rowEnds[$row]) && $rowEnds[$row] > $start) {
                $row++;
            }

            $rowEnds[$row] = $start + self::MARKER_WIDTH;
            $markers[] = [...$this->describe($item, $today), 'left' => round($left, 2), 'row' => $row, 'flip' => $flip];
        }

        return [
            'markers' => $markers,
            'rows' => max(1, count($rowEnds)),
            'width' => self::MARKER_WIDTH,
            'ticks' => $this->ticks($today),
            'expired' => $expired->map(fn (Renewable $item): array => $this->describe($item, $today))->values()->all(),
        ];
    }

    /**
     * @param  class-string<Model&Renewable>  $model
     * @return Collection<int, Model&Renewable>
     */
    private function renewables(string $model, CarbonImmutable $end): Collection
    {
        return $model::query()
            ->with($model::renewalProviderRelation())
            ->withContract()
            ->whereNull('renewal_stopped_at')
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', $end->toDateString())
            ->get();
    }

    /**
     * @return array{name: string, date: string, status: string, color: string, url: string, icon: string, mono: bool}
     */
    private function describe(Renewable $item, CarbonImmutable $today): array
    {
        $status = $item->renewalStatus($today);

        return [
            'name' => $item->name,
            'date' => $item->expires_on->translatedFormat(__('formats.date')),
            'status' => $status->getLabel(),
            'color' => $status->getColor(),
            'url' => RenewalSchema::url($item),
            'icon' => RenewalSchema::icon($item),
            'mono' => RenewalSchema::mono($item),
        ];
    }

    /**
     * A tick on every Monday but one right after today.
     *
     * @return list<array{left: float, label: string}>
     */
    private function ticks(CarbonImmutable $today): array
    {
        $ticks = [];

        for ($monday = $today->next(CarbonInterface::MONDAY); $monday->lt($today->addDays(self::DAYS)); $monday = $monday->addWeek()) {
            $left = $today->diffInDays($monday) / self::DAYS * 100;

            // Too close to "Today" at the start of the line to be read.
            if ($left >= 5) {
                $ticks[] = ['left' => round($left, 2), 'label' => $monday->isoFormat('D MMM')];
            }
        }

        return $ticks;
    }
}
