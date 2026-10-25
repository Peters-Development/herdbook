<?php

namespace Tests\Feature\Costs;

use App\Enums\CostPeriod;
use App\Models\Domain;
use App\Services\Costs\Money;
use BcMath\Number;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    /** @return array<string, array{string, int, string, string}> */
    public static function amounts(): array
    {
        return [
            'cents' => ['9.95', 2, '€ 9,95', '€9.95'],
            'whole' => ['12', 2, '€ 12,00', '€12.00'],
            'thousands' => ['1234567.891', 2, '€ 1.234.567,89', '€1,234,567.89'],
            'rounded up' => ['999.995', 2, '€ 1.000,00', '€1,000.00'],
            'no cents' => ['1234.5', 0, '€ 1.235', '€1,235'],
            'three digits' => ['123.4', 2, '€ 123,40', '€123.40'],
            'zero' => ['0', 2, '€ 0,00', '€0.00'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_amounts_read_as_dutch_euros(string $amount, int $decimals, string $dutch, string $english): void
    {
        app()->setLocale('nl');

        $this->assertSame($dutch, Money::format(new Number($amount), $decimals));
    }

    #[DataProvider('amounts')]
    public function test_amounts_read_as_english_euros(string $amount, int $decimals, string $dutch, string $english): void
    {
        app()->setLocale('en');

        $this->assertSame($english, Money::format(new Number($amount), $decimals));
    }

    public function test_a_decimal_string_is_formatted_too(): void
    {
        app()->setLocale('en');

        $this->assertSame('€1,234.56', Money::format('1234.56'));
    }

    public function test_the_currency_symbol_comes_from_the_config(): void
    {
        config(['herdbook.currency_symbol' => '$']);

        app()->setLocale('en');
        $this->assertSame('$1,234.56', Money::format('1234.56'));

        app()->setLocale('nl');
        $this->assertSame('$ 1.234,56', Money::format('1234.56'));
    }

    public function test_an_item_is_described_as_entered(): void
    {
        app()->setLocale('nl');

        $this->assertSame('€ 9,95 per jaar', Money::describe(new Domain(['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year])));
        $this->assertSame('€ 9,95', Money::describe(new Domain(['cost_amount' => '9.95'])));
        $this->assertNull(Money::describe(new Domain));

        app()->setLocale('en');

        $this->assertSame('€1,234.50', Money::describe(new Domain(['cost_amount' => '1234.5'])));
        $this->assertSame('€9.95 '.CostPeriod::Month->getLabel(), Money::describe(new Domain(['cost_amount' => '9.95', 'cost_period' => CostPeriod::Month])));
    }
}
