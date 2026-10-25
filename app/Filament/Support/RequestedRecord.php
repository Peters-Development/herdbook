<?php

namespace App\Filament\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * An id in the query string of a create page, from a button on another page
 * ("New check" with ?hostname=12, "Add subscription" with ?project=3),
 * to fill in a select.
 */
final class RequestedRecord
{
    /**
     * The id in ?$parameter=, when it is one of $model.
     *
     * @param  class-string<Model>  $model
     */
    public static function id(string $parameter, string $model): ?int
    {
        $value = request()->query($parameter);

        return is_string($value) && ctype_digit($value) && $model::query()->whereKey((int) $value)->exists()
            ? (int) $value
            : null;
    }
}
