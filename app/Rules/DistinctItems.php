<?php

namespace App\Rules;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * For a list of items (a Filament Repeater): no two items may share the same
 * values for $fields. Relationship repeaters write straight into a table
 * with a unique index, which would otherwise answer with a server error.
 */
class DistinctItems implements ValidationRule
{
    /**
     * @param  list<string>  $fields
     * @param  (Closure(mixed): mixed)|null  $normalize  how a value is stored, if not as typed ("fd00:0::1" → "fd00::1")
     */
    public function __construct(
        private readonly array $fields,
        private readonly string $message,
        private readonly ?Closure $normalize = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $keys = collect($value)->map(fn (array $item): string => collect($this->fields)
            ->map(fn (string $field): string => (string) $this->scalar($this->normalize ? ($this->normalize)($item[$field] ?? null) : ($item[$field] ?? null)))
            ->implode('|'));

        if ($keys->count() !== $keys->unique()->count()) {
            $fail($this->message);
        }
    }

    /**
     * Case-insensitive, like MySQL's _ci collations: "NFS" and "nfs" collide
     * on the unique index.
     */
    private function scalar(mixed $value): mixed
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        return is_string($value) ? mb_strtolower(trim($value)) : $value;
    }
}
