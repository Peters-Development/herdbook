<?php

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * A moment as an agent sends it: ISO 8601, 2026-11-12T22:00, with seconds
 * and fractions if need be, and an offset (Z, +01:00) or none; a space for
 * the T is fine. Without an offset it is read in the display timezone, as
 * the forms read what people type. Nothing else and no date that doesn't
 * exist: Carbon would read "tomorrow" too, and roll 2026-02-30 into March.
 */
class Moment implements ValidationRule
{
    private const string FORMAT = '/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])[T ]([01]\d|2[0-3]):[0-5]\d(:[0-5]\d(\.\d{1,6})?)?(Z|[+-](0\d|1[0-4]):[0-5]\d)?\z/';

    /**
     * In UTC. Null: it isn't a moment in this form, or not a date that exists.
     */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::FORMAT, $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, config('herdbook.display_timezone'))->utc();
        } catch (Throwable) {
            return null;
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && self::parse($value) === null) {
            $fail(__('Give a moment as 2026-11-12T22:00, with an offset such as +01:00 or Z if it is not in :timezone.', ['timezone' => config('herdbook.display_timezone')]));
        }
    }
}
