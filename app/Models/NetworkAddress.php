<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device on a network that is not a server in Herdbook ("Router",
 * "NAS", "Printer"), for reference.
 */
#[Fillable(['network_id', 'address', 'name', 'mac_address', 'notes'])]
class NetworkAddress extends Model
{
    use RecordsActivity;

    public $timestamps = false;

    /**
     * Sorts addresses as numbers (192.168.1.9 before 192.168.1.10), IPv4
     * before IPv6; also one key for every way of writing an address
     * ("fd00::1" and "FD00:0::1").
     */
    public static function sortKey(string $address): string
    {
        $packed = @inet_pton($address);

        return $packed === false ? "\u{FFFE}{$address}" : bin2hex(str_pad($packed, 16, "\0", STR_PAD_LEFT));
    }

    /** @return array<string, mixed> */
    protected function activityContext(): array
    {
        return [
            'network_id' => $this->network_id,
            'label' => "{$this->address} ({$this->name})",
        ];
    }

    /** @return BelongsTo<Network, $this> */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * One way of writing it: "fd00::1", not "FD00:0:0::1".
     *
     * @return Attribute<string, string>
     */
    protected function address(): Attribute
    {
        return Attribute::set(function (string $value): string {
            $packed = @inet_pton(trim($value));

            return $packed === false ? trim($value) : (string) inet_ntop($packed);
        });
    }

    /**
     * "02:00:5e:12:34:56" for "02-00-5E-12-34-56", "0200.5e12.3456" or
     * "02005e123456"; null when it is no MAC address.
     */
    public static function normalizeMac(string $value): ?string
    {
        if (preg_match('/^[0-9a-f]{2}([:-]?)([0-9a-f]{2}\1){4}[0-9a-f]{2}$|^([0-9a-f]{4}\.){2}[0-9a-f]{4}$/i', trim($value)) !== 1) {
            return null;
        }

        return implode(':', str_split(strtolower((string) preg_replace('/[^0-9a-f]/i', '', $value)), 2));
    }

    /** @return Attribute<?string, ?string> */
    protected function macAddress(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => blank($value) ? null : (self::normalizeMac($value) ?? trim($value)));
    }
}
