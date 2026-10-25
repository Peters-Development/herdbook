<?php

namespace App\Services\Checks;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * NetworkProbe with PHP's sockets, `dig` (dnsutils) and `ping` (iputils;
 * unprivileged ICMP works in Docker's default network). Both run as
 * processes with a hard time limit: PHP's own name lookups have none.
 */
final class SocketProbe implements NetworkProbe
{
    /**
     * DNS checks ask a public resolver directly, not the host's stub: the
     * answer the world gets, and no hang on a broken local resolver.
     */
    private const string RESOLVER = '1.1.1.1';

    public function tcp(string $host, int $port, float $timeoutSeconds): int
    {
        $started = hrtime(true);
        $socket = @stream_socket_client('tcp://'.self::address($host).":{$port}", $errorCode, $errorMessage, $timeoutSeconds);

        if ($socket === false) {
            throw new ProbeFailed(__('Port :port on :host is not reachable.', ['port' => $port, 'host' => $host]));
        }

        fclose($socket);

        return $this->elapsedMs($started);
    }

    public function certificateExpiry(string $host, int $port, float $timeoutSeconds): CarbonImmutable
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
            // An invalid chain is not this check's question; the expiry is.
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]]);

        $socket = @stream_socket_client('ssl://'.self::address($host).":{$port}", $errorCode, $errorMessage, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            throw new ProbeFailed(__('No TLS connection to :address.', ['address' => "{$host}:{$port}"]));
        }

        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);

        $parsed = $certificate ? openssl_x509_parse($certificate) : false;

        if ($parsed === false || ! isset($parsed['validTo_time_t'])) {
            throw new ProbeFailed(__(':host did not present a readable certificate.', ['host' => $host]));
        }

        return CarbonImmutable::createFromTimestampUTC($parsed['validTo_time_t']);
    }

    public function resolve(string $name): array
    {
        $addresses = [];

        foreach (['A', 'AAAA'] as $type) {
            $result = Process::timeout(6)->run(['dig', '+short', '+time=2', '+tries=1', '@'.self::RESOLVER, $name, $type]);

            if ($result->failed()) {
                throw new ProbeFailed(__('No DNS answer for :name.', ['name' => $name]));
            }

            $addresses = [...$addresses, ...self::addressesFromDig($result->output())];
        }

        if ($addresses === []) {
            throw new ProbeFailed(__(':name has no A or AAAA record.', ['name' => $name]));
        }

        return $addresses;
    }

    public function ping(string $host, float $timeoutSeconds): int
    {
        if (str_starts_with($host, '-')) {
            throw new ProbeFailed(__('Invalid target.'));
        }

        $wait = (string) (int) ceil($timeoutSeconds);
        $result = Process::timeout((int) ceil($timeoutSeconds) + 2)->run(['ping', '-c', '1', '-W', $wait, $host]);

        if ($result->failed()) {
            throw new ProbeFailed(__(':host does not answer ping.', ['host' => $host]));
        }

        return self::milliseconds($result->output()) ?? throw new ProbeFailed(__(':host gave an unreadable ping reply.', ['host' => $host]));
    }

    public function reachable(string $url, float $timeoutSeconds): bool
    {
        try {
            Http::timeout((int) ceil($timeoutSeconds))->connectTimeout((int) ceil($timeoutSeconds))->get($url);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The IP addresses in `dig +short` output; CNAME targets are skipped.
     *
     * @return list<string>
     */
    public static function addressesFromDig(string $output): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $output)),
            fn (string $line): bool => filter_var($line, FILTER_VALIDATE_IP) !== false,
        ));
    }

    /**
     * The round trip from `ping` output ("time=9.81 ms").
     */
    public static function milliseconds(string $pingOutput): ?int
    {
        return preg_match('/time[=<]([\d.]+) ?ms/', $pingOutput, $matches) === 1 ? (int) round((float) $matches[1]) : null;
    }

    /**
     * An IPv6 address goes in brackets in a socket URI.
     */
    public static function address(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$host}]" : $host;
    }

    private function elapsedMs(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
