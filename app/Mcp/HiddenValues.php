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
 * to what comes before it, "%2F192.0.2.10"), runs of labels in any script
 * when Herdbook knows an internationalised name (as written), and runs of
 * labels (names, a known one also inside a longer name, glued to what comes
 * before it or after it with a dash, and IPv4 addresses spelled with dashes
 * in cloud hostnames). Each looked up in
 * a table, so ports, URLs and user@host fall away. Each pass writes
 * placeholders, so no mask is masked again. A time such as 12:30:45 is no
 * address. Best effort: an address in a form nothing here expects (octal,
 * split over lines) gets through. When a pattern fails, the whole text is
 * hidden: better nothing than an address. Built once per request (scoped).
 */
class HiddenValues
{
    private const string MAC = '/(?<![0-9A-Za-z])(?:[0-9A-Fa-f]{2}(?:[:-][0-9A-Fa-f]{2}){5,}|[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}|[0-9A-Fa-f]{12})(?![0-9A-Za-z])/';

    private const string IPV6 = '/(?<![0-9A-Za-z])((?:%[0-9A-Fa-f]{2})*)([0-9A-Za-z]*?)((?:[0-9A-Fa-f]{0,4}:){2,7}(?:[0-9A-Fa-f]{1,4}|\d{1,3}(?:\.\d{1,3}){3})?)(?![0-9A-Za-z]|\.\d)/';

    private const string IPV4 = '/(?<![0-9])\d+(?:\.\d{1,3}){3,}(?![0-9])/';

    private const string LABELS = '/[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*+/';

    /**
     * Labels in any script, marks included (Hindi, Thai): only when an
     * internationalised name is known, and before LABELS, which would cut it
     * at its first letter outside ASCII. Not instead of LABELS: letters glued
     * to a name ("服务器vps-1.example") would hide it.
     */
    private const string UNICODE_LABELS = '/[\p{L}\p{N}](?:[\p{L}\p{M}\p{N}-]*[\p{L}\p{M}\p{N}])?(?:\.[\p{L}\p{N}](?:[\p{L}\p{M}\p{N}-]*[\p{L}\p{M}\p{N}])?)*+/u';

    /**
     * The longest a DNS label is: no known name ends further into one.
     */
    private const int MAX_LABEL = 63;

    /**
     * The most labels a value has (an FQDN): no part of a longer run is
     * looked up beyond it, so a run of thousands costs no more than a few.
     */
    private const int MAX_LABELS = 12;

    /** @var array<string, string>|null lowercased (IPv6: normalized) value => mask */
    private ?array $known = null;

    /** @var array<string, array<string, string>> a known name without its first label => first label => mask */
    private array $tails = [];

    /** Whether a known name is internationalised (written outside ASCII). */
    private bool $unicode = false;

    /** @var list<string> the masks of the text being masked, by placeholder number */
    private array $masks = [];

    public function mask(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $this->masks = [];
        $masked = $this->replace(self::MAC, $text, fn (array $m): string => $this->mac($m[0]));
        // What is URL-encoded in front ("%5B") is no letters glued to it.
        $masked = $this->replace(self::IPV6, $masked, fn (array $m): string => $m[1].$m[2].$this->ipv6($m[3], glued: $m[2] !== ''));
        $masked = $this->replace(self::IPV4, $masked, fn (array $m): string => $this->ipv4($m[0]));

        if ($masked !== null && $this->knowsUnicodeNames()) {
            $masked = $this->replace(self::UNICODE_LABELS, $masked, fn (array $m): string => $this->labels($m[0]));
        }

        $masked = $this->replace(self::LABELS, $masked, fn (array $m): string => $this->labels($m[0]));

        if ($masked === null) {
            return $this->hiddenText();
        }

        return strtr($masked, array_combine(array_map(self::placeholder(...), array_keys($this->masks)), $this->masks));
    }

    /**
     * What a text reads as when it couldn't be masked: an agent read none
     * of it, so it can't write over it either (WritesTexts::checkTexts()).
     */
    public function hiddenText(): string
    {
        return '['.__('text hidden').']';
    }

    /**
     * Once more without PCRE's JIT when its stack ran out (a long run of
     * labels): slower, but with a stack of its own.
     *
     * @param  callable(array<int, string>): string  $mask
     */
    private function replace(string $pattern, ?string $text, callable $mask): ?string
    {
        if ($text === null) {
            return null;
        }

        $masked = preg_replace_callback($pattern, $mask, $text);

        if ($masked === null && preg_last_error() === PREG_JIT_STACKLIMIT_ERROR) {
            $jit = (string) ini_get('pcre.jit');
            ini_set('pcre.jit', '0');

            try {
                $masked = preg_replace_callback($pattern, $mask, $text);
            } finally {
                ini_set('pcre.jit', $jit);
            }
        }

        return $masked;
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
        // Each digit a character of its own: strtr() with two strings would go by byte.
        return "\u{E000}".implode(array_map(fn (string $digit): string => mb_chr(0xE010 + (int) $digit), str_split((string) $number)))."\u{E001}";
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
     * without a leading colon ("addr:fd00::5"). With letters glued to its
     * first group, two groups are code, not an address ("Cache::add",
     * "std::cafe"), unless Herdbook knows it.
     */
    private function ipv6(string $token, bool $glued): string
    {
        if ($glued && ! str_starts_with($token, ':') && count(array_filter(explode(':', $token), fn (string $group): bool => $group !== '')) < 3
            && ! isset($this->known()[$this->normalized($token)])) {
            return $token;
        }

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
     * it ("2Fvps-123.provider.example") or after it with a dash
     * ("vps-123.provider.example-access.log") too.
     */
    private function labels(string $token): string
    {
        $labels = explode('.', $token);
        $count = count($labels);
        $out = [];

        for ($start = 0; $start < $count; $start++) {
            for ($end = min($count, $start + self::MAX_LABELS); $end > $start; $end--) {
                $slice = array_slice($labels, $start, $end - $start);

                if (($mask = $this->known()[mb_strtolower(implode('.', $slice))] ?? null) !== null) {
                    $out[] = $this->hold($mask);
                    $start = $end - 1;

                    continue 2;
                }

                $glued = $end - $start > 1 ? $this->glued($labels[$start], implode('.', array_slice($slice, 1))) ?? $this->suffixed($slice) : null;

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
        $labels = $this->tails[mb_strtolower($tail)] ?? [];

        if ($labels === []) {
            return null;
        }

        // The longest known first label it ends with, looked up by its
        // length (no label is longer than MAX_LABEL), not label by label: a
        // tail can be shared by hundreds of servers.
        $length = mb_strlen($first);
        $end = mb_strtolower(mb_substr($first, -self::MAX_LABEL));

        for ($size = min($length - 1, mb_strlen($end)); $size > 0; $size--) {
            if (($mask = $labels[mb_substr($end, -$size)] ?? null) !== null) {
                return $this->ipInLabel(mb_substr($first, 0, $length - $size)).$this->hold($mask);
            }
        }

        return null;
    }

    /**
     * $labels are a known name, but for what follows a dash in the last
     * (the longest such name, within the first MAX_LABEL characters), and
     * maybe what is glued to the first.
     *
     * @param  list<string>  $labels
     */
    private function suffixed(array $labels): ?string
    {
        $last = (string) array_pop($labels);

        for ($dash = strrpos(substr($last, 0, self::MAX_LABEL + 1), '-'); $dash !== false && $dash > 0; $dash = strrpos(substr($last, 0, $dash), '-')) {
            $name = [...$labels, substr($last, 0, $dash)];
            $mask = $this->known()[mb_strtolower(implode('.', $name))] ?? null;
            $masked = $mask !== null ? $this->hold($mask) : $this->glued($name[0], implode('.', array_slice($name, 1)));

            if ($masked !== null) {
                return $masked.$this->ipInLabel(substr($last, $dash));
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

    private function knowsUnicodeNames(): bool
    {
        $this->known();

        return $this->unicode;
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
            $value = mb_strtolower(trim((string) $value));

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

            foreach (self::hostnames($server->provider_hostname) as $hostname) {
                $add($hostname, $server->name);
            }
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

        $this->unicode = preg_grep('/[^\x00-\x7F]/', array_map(strval(...), array_keys($this->known))) !== [];

        foreach ($this->known as $value => $mask) {
            if (str_contains($value, '.') && ! filter_var($value, FILTER_VALIDATE_IP)) {
                [$first, $tail] = explode('.', $value, 2);
                $this->tails[$tail][$first] = $mask;
            }
        }

        return $this->known;
    }

    /**
     * The hostnames in what was filled in as a provider hostname, free text
     * ("vps-1.provider.example (rescue)", "Hetzner:vps-2.provider.example",
     * a URL, more than one): every run of dotted labels, an internationalised
     * one in punycode and as written. One without a dot is a name, often the
     * server's own ("hz-1"), and one that ends in a number a version
     * ("v2.1"), not an address.
     *
     * @return list<string>
     */
    private static function hostnames(?string $value): array
    {
        $names = [];

        foreach (preg_match_all(self::UNICODE_LABELS, (string) $value, $runs) ? $runs[0] : [] as $host) {
            if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
                $host = (string) idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            }

            if (str_contains($host, '.') && preg_match('/^[A-Za-z0-9.-]+$/', $host) === 1
                && (filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('/[A-Za-z]/', (string) strrchr($host, '.')) === 1)) {
                array_push($names, $host, (string) idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46));
            }
        }

        return array_values(array_unique(array_filter($names)));
    }
}
