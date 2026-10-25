<?php

namespace App\Services\Costs;

use App\Models\Contracts\Renewable;
use BcMath\Number;
use Illuminate\Database\Eloquent\Model;

/**
 * One item in a CostGroup: what it costs per year, and for whom else when
 * it is shared.
 */
final readonly class CostLine
{
    public function __construct(
        public Model&Renewable $item,
        public Number $yearly,
        /** Who it is shared with ("Studbook, Pasture Planner"); null when it is not. */
        public ?string $detail = null,
    ) {}
}
