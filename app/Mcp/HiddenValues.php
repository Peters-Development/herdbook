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
 * stops compiling at a few hundred servers. Names and IPv4 addresses are
 * runs of labels (192.0.2.10 in "https://192.0.2.10:8443/x" or
 * "backup@192.0.2.10:/backups"), looked up whole and in part, so a
 * provider hostname inside a longer name is found too. A time such as
 * 12:30:45 is no address. When a pattern fails, the whole text is hidden:
 * better nothing than an address. Built once per request (scoped).
 */
final class HiddenValues
{
    private const string LABELS = '/[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*/';

    private const string IPV6 = '/(?<![0-9A-Za-z])(?:[0-9A-Fa-f]{0,4}:){2,7}(?:[0-9A-Fa-f]{1,4}|\d{1,3}(?:\.\d{1,3}){3})?(?![0-9A-Za-z])/';

    private const string MAC = '/(?<![0-9A-Fa-f:-])[0-9A-Fa-f]{2}(?:[:-][0-9A-Fa-f]{2}){5}(?![0-9A-Fa-f:-])/';

    /**
     * The most labels a value has (an FQDN): no part of a longer run is
     * looked up beyond it, so a run of thousands costs no more than a few.
     */
    private const int MAX_LABELS = 12;

    /** @var array<string, string>|null lowercased value => mask */
    private ?array $known = null;

    public function mask(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $masked = $this->replace(self::MAC, $text, fn (string $mac): string => $this->known()[strtolower(str_replace('-', ':', $mac))] ?? $mac);
        $masked = $this->replace(self::IPV6, $masked, fn (string $token): string => $this->ipv6($token));
        $masked = $this->replace(self::LABELS, $masked, fn (string $token): string => $this->labels($token));

        return $masked ?? '['.__('text hidden').']';
    }

    /**
     * @param  callable(string): string  $mask
     */
    private function replace(string $pattern, ?string $text, callable $mask): ?string
    {
        return $text === null ? null : preg_replace_callback($pattern, fn (array $match): string => $mask($match[0]), $text);
    }

    /**
     * A run of labels: the longest part that is a known value or an IPv4
     * address is masked, from the left, again after it.
     */
    private function labels(string $token): string
    {
        $labels = explode('.', $token);
        $count = count($labels);
        $out = [];

        for ($start = 0; $start < $count; $start++) {
            for ($end = min($count, $start + self::MAX_LABELS); $end > $start; $end--) {
                if (($mask = $this->maskOf(implode('.', array_slice($labels, $start, $end - $start)))) !== null) {
                    $out[] = $mask;
                    $start = $end - 1;

                    continue 2;
                }
            }

            $out[] = $this->ipInLabel($labels[$start]);
        }

        return implode('.', $out);
    }

    private function maskOf(string $value): ?string
    {
        return $this->known()[strtolower($value)]
            ?? (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? '['.__('address').']' : null);
    }

    /**
     * A label that spells an IPv4 address with dashes, as cloud hostnames
     * do ("ip-10-0-0-5", "ec2-192-0-2-10").
     */
    private function ipInLabel(string $label): string
    {
        return preg_replace_callback(
            '/(?<![0-9])\d{1,3}(?:-\d{1,3}){3}(?![0-9])/',
            fn (array $match): string => filter_var(str_replace('-', '.', $match[0]), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? '['.__('address').']' : $match[0],
            $label,
        ) ?? '['.__('address').']';
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

                return substr($token, 0, strlen($token) - strlen($candidate)).$mask;
            }
        }

        return $token;
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
            foreach ([...Server::ADDRESSES, 'provider_hostname'] as $column) {
                $add($server->{$column}, $server->name);
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
            $add(str_replace('-', ':', (string) $address->mac_address), $address->name);
        }

        // A value records share names none of them: 192.168.1.1 is the gateway of many a network.
        return $this->known = array_map(
            fn (array $names): string => count($names) === 1 ? '['.__('address of :name', ['name' => array_key_first($names)]).']' : '['.__('address').']',
            $owners,
        );
    }
}
