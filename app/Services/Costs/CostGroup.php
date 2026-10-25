<?php

namespace App\Services\Costs;

use BcMath\Number;
use Illuminate\Database\Eloquent\Model;

/**
 * What one provider, project or client costs per year, and of what. Without
 * a model it is a bucket: shared, no project, our own.
 */
final readonly class CostGroup
{
    /**
     * @param  Number  $total  of its own items; what it shares is counted under "Shared"
     * @param  list<CostLine>  $items  the most expensive first
     * @param  list<CostLine>  $shared  what it shares with other groups, the most expensive first
     */
    public function __construct(
        public string $label,
        public ?Model $model,
        public Number $total,
        public array $items,
        public array $shared = [],
    ) {}

    public function sharedTotal(): Number
    {
        return Money::sum(array_map(fn (CostLine $line): Number => $line->yearly, $this->shared));
    }
}
