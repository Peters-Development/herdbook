<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Database\Factories\DomainPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a registrar's price list: what a domain with this extension
 * costs per year, excluding VAT. It counts for the domains of that
 * registrar without an amount of their own (Domain::costAmount()).
 */
#[Fillable(['provider_id', 'extension', 'cost_amount'])]
class DomainPrice extends Model
{
    /** @use HasFactory<DomainPriceFactory> */
    use HasFactory, RecordsActivity;

    /**
     * One way of writing it: "com", not ".COM." (as Domain::extensionOf()
     * reads a domain name, which is stored in lower case).
     */
    public static function normalize(string $extension): string
    {
        return trim(mb_strtolower(trim($extension)), '.');
    }

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** @return array<string, mixed> */
    protected function activityContext(): array
    {
        return [
            'provider_id' => $this->provider_id,
            'label' => ".{$this->extension}",
        ];
    }

    /** @return Attribute<string, string> */
    protected function extension(): Attribute
    {
        return Attribute::set(fn (string $value): string => self::normalize($value));
    }

    protected function casts(): array
    {
        return [
            'cost_amount' => 'decimal:2',
        ];
    }
}
