<?php

namespace App\Mcp;

use App\Models\Network;
use App\Models\NetworkAddress;
use App\Models\Server;

/**
 * Masks what an agent never gets in text it reads anyway: incidents carry
 * what monitoring wrote, and a check is named after its target ("Ping
 * 192.0.2.10"). A known address, provider hostname or MAC address reads as
 * "[address of app-1]" ("[address]" when records share it), any other IP
 * address as "[address]".
 *
 * Token by token, not with one pattern of every value: a pattern that size
 * stops compiling at a few hundred servers. In turn: MAC addresses, IPv6
 * addresses, runs of dotted numbers (IPv4, also with leading zeros or glued
 * to what comes before it, "%2F192.0.2.10"), and runs of labels (names, a
 * known one also inside a longer name or glued to what comes before it, and
 * IPv4 addresses spelled with dashes in cloud hostnames). Each looked up in
 * a table, so ports, URLs and user@host fall away. Each pass writes
 * placeholders, so no mask is masked again. A time such as 12:30:45 is no
 * address. Best effort: an address in a form nothing here expects (octal,
 * split over lines) gets through. When a pattern fails, the whole text is
 * hidden: better nothing than an address. Built once per request (scoped).
 */
final class HiddenValues
{
    private const string MAC = '/(?<![0-9A-Za-z])(?:[0-9A-Fa-f]{2}(?:[:-][0-9A-Fa-f]{2}){5,}|[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}|[0-9A-Fa-f]{12})(?![0-9A-Za-z])/';

    private const string IPV6 = '/(?<![0-9A-Za-z])([0-9A-Za-z]*?)((?:[0-9A-Fa-f]{0,4}:){2,7}(?:[0-9A-Fa-f]{1,4}|\d{1,3}(?:\.\d{1,3}){3})?)(?![0-9A-Za-z]|\.\d)/';

    private const string IPV4 = '/(?<![0-9])\d+(?:\.\d{1,3}){3,}(?![0-9])/';

    private const string LABELS = '/[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*/';

    /**
     * The most labels a value has (an FQDN): no part of a longer run is
     * looked up beyond it, so a run of thousands costs no more than a few.
     */
    private const int MAX_LABELS = 12;

    /** @var array<string, string>|null lowercased (IPv6: normalized) value => mask */
    private ?array $known = null;

    /** @var array<string, array<string, string>> a known name without its first label => first label => mask */
    private array $tails = [];

    /** @var list<string> the masks of the text being masked, by placeholder number */
    private array $masks = [];

    public function mask(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $this->masks = [];
        $masked = $this->replace(self::MAC, $text, fn (array $m): string => $this->mac($m[0]));
        $masked = $this->replace(self::IPV6, $masked, fn (array $m): string => $m[1].$this->ipv6($m[2]));
        $masked = $this->replace(self::IPV4, $masked, fn (array $m): string => $this->ipv4($m[0]));
        $masked = $this->replace(self::LABELS, $masked, fn (array $m): string => $this->labels($m[0]));

        if ($masked === null) {
            return '['.__('text hidden').']';
        }

        return strtr($masked, array_combine(array_map(self::placeholder(...), array_keys($this->masks)), $this->masks));
    }

    /**
     * @param  callable(array<int, string>): string  $mask
     */
    private function replace(string $pattern, ?string $text, callable $mask): ?string
    {
        return $text === null ? null : preg_replace_callback($pattern, $mask, $text);
    }

    /**
     * Where a mask goes until all passes are done: characters of Unicode's
     * private use area, which no pass looks at, so no mask is masked again.
     */
    private function hold(string $mask): string
    {
        $this->masks[] = $mask;

        return self::placeholder(count($this->masks) - 1);
    }

    private static function placeholder(int $number): string
    {
        return "\u{E000}".strtr((string) $number, '0123456789', "\u{E010}\u{E011}\u{E012}\u{E013}\u{E014}\u{E015}\u{E016}\u{E017}\u{E018}\u{E019}")."\u{E001}";
    }

    /**
     * A run of MAC-shaped hex: masked when six groups of it are a known MAC
     * (a DHCP client id has one more in front).
     */
    private function mac(string $run): string
    {
        $hex = strtolower((string) preg_replace('/[^0-9A-Fa-f]/', '', $run));

        for ($i = 0; $i + 12 <= strlen($hex); $i += 2) {
            if (($mask = $this->known()[implode(':', str_split(substr($hex, $i, 12), 2))] ?? null) !== null) {
                return $this->hold($mask);
            }
        }

        return $run;
    }

    /**
     * What looks like an IPv6 address: masked when it is one, with or
     * without a leading colon ("addr:2001:db8::10").
     */
    private function ipv6(string $token): string
    {
        foreach ([$token, ltrim($token, ':')] as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $mask = $this->known()[$this->normalized($candidate)] ?? '['.__('address').']';

                return substr($token, 0, strlen($token) - strlen($candidate)).$this->hold($mask);
            }
        }

        return $token;
    }

    /**
     * Four or more dotted numbers: masked whole when four of them are an
     * IPv4 address, leading zeros left out ("192.000.002.010"); the first
     * may have more digits glued in front ("%2F192.0.2.10" leaves "2F192").
     */
    private function ipv4(string $run): string
    {
        $parts = explode('.', $run);
        $generic = false;

        for ($i = 0; $i + 4 <= count($parts); $i++) {
            $heads = $i > 0 ? [$parts[$i]] : array_map(fn (int $length): string => substr($parts[0], -$length), range(1, min(3, strlen($parts[0]))));

            foreach ($heads as $head) {
                $candidate = implode('.', array_map(fn (string $part): string => ltrim($part, '0') === '' ? '0' : ltrim($part, '0'), [$head, ...array_slice($parts, $i + 1, 3)]));

                if (isset($this->known()[$candidate])) {
                    return $this->hold($this->known()[$candidate]);
                }

                $generic = $generic || filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            }
        }

        return $generic ? $this->hold('['.__('address').']') : $run;
    }

    /**
     * A run of labels: the longest part that is a known value is masked,
     * from the left, again after it; a known name glued to what comes before
     * it ("2Fvps-123.provider.example") too.
     */
    private function labels(string $token): string
    {
        $labels = explode('.', $token);
        $count = count($labels);
        $out = [];

        for ($start = 0; $start < $count; $start++) {
            for ($end = min($count, $start + self::MAX_LABELS); $end > $start; $end--) {
                if (($mask = $this->known()[strtolower(implode('.', array_slice($labels, $start, $end - $start)))] ?? null) !== null) {
                    $out[] = $this->hold($mask);
                    $start = $end - 1;

                    continue 2;
                }

                $glued = $end - $start > 1 ? $this->glued($labels[$start], implode('.', array_slice($labels, $start + 1, $end - $start - 1))) : null;

                if ($glued !== null) {
                    $out[] = $glued;
                    $start = $end - 1;

                    continue 2;
                }
            }

            $out[] = $this->ipInLabel($labels[$start]);
        }

        return implode('.', $out);
    }

    /**
     * $first and $tail are a known name, but for what is glued to $first.
     */
    private function glued(string $first, string $tail): ?string
    {
        $this->known();

        foreach ($this->tails[strtolower($tail)] ?? [] as $label => $mask) {
            if (strlen($first) > strlen($label) && str_ends_with(strtolower($first), $label)) {
                return substr($first, 0, strlen($first) - strlen($label)).$this->hold($mask);
            }
        }

        return null;
    }

    /**
     * A label that spells an IPv4 address with dashes, as cloud hostnames
     * do ("ip-10-0-0-5", "ec2-192-0-2-10").
     */
    private function ipInLabel(string $label): string
    {
        return preg_replace_callback('/(?<![0-9A-Za-z])\d{1,3}(?:-\d{1,3}){3}(?![0-9])/', function (array $match): string {
            $address = str_replace('-', '.', $match[0]);

            return match (true) {
                isset($this->known()[$address]) => $this->hold($this->known()[$address]),
                filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => $this->hold('['.__('address').']'),
                default => $match[0],
            };
        }, $label) ?? $this->hold('['.__('address').']');
    }

    private function normalized(string $ipv6): string
    {
        $packed = @inet_pton($ipv6);

        return $packed === false ? strtolower($ipv6) : (string) inet_ntop($packed);
    }

    /**
     * @return array<string, string> lowercased (IPv6: normalized) value => mask
     */
    private function known(): array
    {
        if ($this->known !== null) {
            return $this->known;
        }

        /** @var array<string, array<string, true>> $owners value => names */
        $owners = [];
        $add = function (?string $value, string $name) use (&$owners): void {
            $value = strtolower(trim((string) $value));

            if (mb_strlen($value) < 3) {
                return;
            }

            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $value = $this->normalized($value);
            }

            $owners[$value][$name] = true;
        };

        foreach (Server::query()->get(['name', 'provider_hostname', ...Server::ADDRESSES]) as $server) {
            foreach (Server::ADDRESSES as $column) {
                $add($server->{$column}, $server->name);
            }

            $add(self::hostname($server->provider_hostname), $server->name);
        }

        foreach (Network::query()->get(['name', 'subnet', 'gateway', 'public_ip']) as $network) {
            $add($network->gateway, $network->name);
            $add($network->public_ip, $network->name);
            // The subnet's address, as it appears before its prefix.
            $add(explode('/', (string) $network->subnet)[0], $network->name);
        }

        foreach (NetworkAddress::query()->get(['name', 'address', 'mac_address']) as $address) {
            $add($address->address, $address->name);
            $hex = strtolower((string) preg_replace('/[^0-9A-Fa-f]/', '', (string) $address->mac_address));
            $add(strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null, $address->name);
        }

        // A value records share names none of them: 192.168.1.1 is the gateway of many a network.
        $this->known = array_map(
            fn (array $names): string => count($names) === 1 ? '['.__('address of :name', ['name' => array_key_first($names)]).']' : '['.__('address').']',
            $owners,
        );

        foreach ($this->known as $value => $mask) {
            if (str_contains($value, '.') && ! filter_var($value, FILTER_VALIDATE_IP)) {
                [$first, $tail] = explode('.', $value, 2);
                $this->tails[$tail][$first] = $mask;
            }
        }

        return $this->known;
    }

    /**
     * A provider hostname as a name in text: the host of a URL, without a
     * trailing dot, an internationalised one in punycode. One without a dot
     * is a name, often the server's own ("hz-1"), not an address.
     */
    private static function hostname(?string $value): ?string
    {
        $value = trim((string) $value);
        $value = str_contains($value, '://') ? (string) parse_url($value, PHP_URL_HOST) : $value;
        $value = rtrim($value, '.');

        if (preg_match('/[^\x00-\x7F]/', $value) === 1) {
            $value = (string) idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        }

        return str_contains($value, '.') && preg_match('/^[A-Za-z0-9.-]+$/', $value) === 1 ? $value : null;
    }
}
