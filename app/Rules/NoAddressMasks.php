<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Text from agents holds no mask of an address ("[address of app-1]",
 * HiddenValues): an agent that writes back a text it read masked would put
 * the mask where the address was, and the address would be lost. In every
 * language Herdbook speaks, as an agent reads masks in its account's.
 */
class NoAddressMasks implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match(self::pattern(), $value) === 1) {
            $fail(__('This holds a mask such as [address of app-1]: the address it stands for would be lost. Leave the masks out, or change the text in Herdbook itself.'));
        }
    }

    private static function pattern(): string
    {
        $masks = [];

        foreach (array_keys((array) config('herdbook.locales')) as $locale) {
            foreach ([__('address', [], $locale), __('text hidden', [], $locale)] as $mask) {
                $masks[] = preg_quote('['.$mask.']', '/');
            }

            // "[address of :name]", up to the name.
            $masks[] = preg_quote('['.explode(':name', __('address of :name', [], $locale))[0], '/');
        }

        return '/'.implode('|', array_unique($masks)).'/iu';
    }
}
