<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A subnet in CIDR notation: "192.168.60.0/24" or "fd00::/64".
 * normalize() turns an address with host bits into the network's own
 * ("192.168.60.7/24" → "192.168.60.0/24").
 */
class Subnet implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail(__('Enter a subnet such as 192.168.1.0/24.'));
        }
    }

    public static function isValid(mixed $value): bool
    {
        if (! is_string($value) || preg_match('#^([^/\s]+)/(0|[1-9]\d{0,2})$#', $value, $parts) !== 1) {
            return false;
        }

        [, $address, $prefix] = $parts;

        return match (true) {
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => (int) $prefix <= 32,
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => (int) $prefix <= 128,
            default => false,
        };
    }

    public static function normalize(string $value): string
    {
        if (! self::isValid($value)) {
            return $value;
        }

        [$address, $prefix] = explode('/', $value);
        $bits = (int) $prefix;
        $network = '';

        foreach (str_split((string) inet_pton($address)) as $index => $byte) {
            $kept = max(0, min(8, $bits - $index * 8));
            $network .= chr(ord($byte) & (0xFF << (8 - $kept)) & 0xFF);
        }

        return inet_ntop($network)."/{$bits}";
    }
}
