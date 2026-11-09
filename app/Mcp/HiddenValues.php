<?php

namespace App\Mcp;

use App\Models\Network;
use App\Models\NetworkAddress;
use App\Models\Server;

/**
 * Masks what an agent never gets in text it reads anyway: incidents carry
 * what monitoring wrote, and a check is named after its target ("Ping
 * 192.0.2.10"). A known address or provider hostname reads as "[address of
 * app-1]", any other IP address as "[address]". Matched whole only, so
 * 192.0.2.1 leaves 192.0.2.10 alone, and a time such as 12:30:45 is no
 * address. Built once per request (scoped in AppServiceProvider).
 *
 * Text masked this way is not text an agent may write back over: it would
 * replace the address with the mask (ChangesIncident).
 */
final class HiddenValues
{
    /** @var array<string, string>|null lowercased value => mask */
    private ?array $known = null;

    public function mask(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $known = $this->known();

        if ($known !== []) {
            $alternatives = implode('|', array_map(fn (string $value): string => preg_quote($value, '/'), array_keys($known)));
            $text = preg_replace_callback(
                '/(?<![\w.:\/-])(?:'.$alternatives.')(?![\w:\/-]|\.\w)/iu',
                fn (array $match): string => $known[mb_strtolower($match[0])],
                $text,
            ) ?? $text;
        }

        // Any other address: what looks like one and is one.
        return preg_replace_callback(
            '/(?<![\w.:])(?:\d{1,3}(?:\.\d{1,3}){3}|[0-9a-f]{0,4}(?::[0-9a-f]{0,4}){2,7}(?:\d{1,3}(?:\.\d{1,3}){3})?)(?![\w:]|\.\w)/i',
            fn (array $match): string => filter_var($match[0], FILTER_VALIDATE_IP) === false ? $match[0] : '['.__('address').']',
            $text,
        ) ?? $text;
    }

    /**
     * Whether masking changes $text: an agent read it masked.
     */
    public function hides(?string $text): bool
    {
        return $this->mask($text) !== $text;
    }

    /**
     * @return array<string, string> lowercased value => mask, longest first
     */
    private function known(): array
    {
        if ($this->known !== null) {
            return $this->known;
        }

        $known = [];
        $add = function (?string $value, string $of) use (&$known): void {
            if (filled($value) && mb_strlen(trim($value)) >= 3) {
                $known[mb_strtolower(trim($value))] ??= '['.__('address of :name', ['name' => $of]).']';
            }
        };

        foreach (Server::query()->get(['name', 'provider_hostname', ...Server::ADDRESSES]) as $server) {
            foreach ([...Server::ADDRESSES, 'provider_hostname'] as $column) {
                $add($server->{$column}, $server->name);
            }
        }

        foreach (Network::query()->get(['name', 'subnet', 'gateway', 'public_ip']) as $network) {
            foreach (['subnet', 'gateway', 'public_ip'] as $column) {
                $add($network->{$column}, $network->name);
            }
        }

        foreach (NetworkAddress::query()->get(['name', 'address', 'mac_address']) as $address) {
            $add($address->address, $address->name);
            $add($address->mac_address, $address->name);
        }

        uksort($known, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $this->known = $known;
    }
}
