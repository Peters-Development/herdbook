<?php

namespace App\Services\Checks;

use App\Enums\CheckType;
use App\Models\Check;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs checks and returns what they found; storing and judging the results
 * is CheckStateMachine's job (RunChecks). HTTP checks run concurrently
 * (runHttp); the others one by one (runOne), each bounded in time. Nothing
 * here throws: whatever goes wrong is a "down" with a reason in the active
 * language (it is shown in the panel).
 */
final class CheckRunner
{
    public function __construct(
        private readonly NetworkProbe $probe,
        private readonly DnsExpectation $dnsExpectation,
    ) {}

    /**
     * @param  Collection<int, Check>  $checks
     * @return array<int, CheckOutcome> check id => outcome
     */
    public function run(Collection $checks): array
    {
        [$http, $other] = $checks->partition(fn (Check $check): bool => $check->type->isHttp());

        $outcomes = $this->runHttp($http);

        foreach ($other as $check) {
            $outcomes[$check->id] = $this->runOne($check);
        }

        return $outcomes;
    }

    /**
     * @param  Collection<int, Check>  $checks  HTTP and keyword checks
     * @return array<int, CheckOutcome>
     */
    public function runHttp(Collection $checks): array
    {
        [$valid, $invalid] = $checks->partition(fn (Check $check): bool => Check::isValidHttpTarget($check->target));

        $outcomes = $invalid->mapWithKeys(fn (Check $check): array => [$check->id => CheckOutcome::down(__('Invalid URL.'))])->all();

        if ($valid->isEmpty()) {
            return $outcomes;
        }

        try {
            $responses = Http::pool(fn (Pool $pool): array => $valid
                ->map(fn (Check $check) => $this->request($pool->as((string) $check->id), $check)->get($check->target))
                ->all());
        } catch (Throwable $exception) {
            // Something no URL check foresaw: fall back to one request per
            // check, so one bad check does not take the batch down with it.
            report($exception);

            return $outcomes + $valid->mapWithKeys(fn (Check $check): array => [$check->id => $this->runSingleHttp($check)])->all();
        }

        return $outcomes + $valid->mapWithKeys(fn (Check $check): array => [
            $check->id => $this->judgeHttp($check, $responses[(string) $check->id] ?? null),
        ])->all();
    }

    private function runSingleHttp(Check $check): CheckOutcome
    {
        try {
            return $this->judgeHttp($check, $this->request(Http::createPendingRequest(), $check)->get($check->target));
        } catch (Throwable $exception) {
            return $this->judgeHttp($check, $exception);
        }
    }

    private function request(PendingRequest $request, Check $check): PendingRequest
    {
        $timeout = (int) config('herdbook.checks.timeout_seconds');

        return $request
            ->timeout($timeout)
            ->connectTimeout(min(5, $timeout))
            ->withUserAgent((string) config('herdbook.checks.user_agent'))
            // An expected redirect is checked as such; otherwise follow up to 3.
            ->withOptions(['allow_redirects' => $check->followsRedirects() ? ['max' => 3] : false])
            // A certificate of a CA of its own (Caddy's on the LAN): the devices
            // trust it, the host does not. Whether the page answers is the question.
            ->when($check->acceptsAnyCertificate(), fn (PendingRequest $request): PendingRequest => $request->withoutVerifying());
    }

    private function judgeHttp(Check $check, mixed $response): CheckOutcome
    {
        if (! $response instanceof Response) {
            $reason = $response instanceof Throwable ? $this->shortReason($response) : __('no response');

            return CheckOutcome::down(__('No response: :reason', ['reason' => $reason]));
        }

        $milliseconds = isset($response->handlerStats()['total_time'])
            ? (int) round($response->handlerStats()['total_time'] * 1000)
            : null;
        $redirect = $check->expectedRedirect();

        if ($redirect !== null) {
            return $this->judgeRedirect($check, $response, $redirect, $milliseconds);
        }

        $expected = $check->setting('expected_status');
        // Redirects are followed (at most 3): a final 3xx is a loop or a chain
        // that does not end, not a working page.
        $statusOk = $expected !== null ? $response->status() === (int) $expected : $response->successful();

        if (! $statusOk) {
            $error = $expected !== null
                ? __('HTTP :status, expected :expected', ['status' => $response->status(), 'expected' => $expected])
                : "HTTP {$response->status()}";

            return CheckOutcome::down($error, $milliseconds, $response->status());
        }

        $keyword = $check->setting('keyword');

        if ($check->type === CheckType::Keyword && filled($keyword) && ! str_contains(mb_strtolower($response->body()), mb_strtolower((string) $keyword))) {
            return CheckOutcome::down(__('Keyword ":keyword" not found', ['keyword' => $keyword]), $milliseconds, $response->status());
        }

        return CheckOutcome::up($milliseconds, $response->status());
    }

    /**
     * A redirect (with the expected code, when one is set) whose Location
     * names $host. A relative Location stays on the check's own host.
     */
    private function judgeRedirect(Check $check, Response $response, string $host, ?int $milliseconds): CheckOutcome
    {
        $status = $response->status();
        $expected = $check->setting('expected_status');

        if (! $response->redirect()) {
            return CheckOutcome::down(__('HTTP :status, expected a redirect to :host', ['status' => $status, 'host' => $host]), $milliseconds, $status);
        }

        if (filled($expected) && $status !== (int) $expected) {
            return CheckOutcome::down(__('HTTP :status, expected :expected', ['status' => $status, 'expected' => $expected]), $milliseconds, $status);
        }

        $location = trim($response->header('Location'));

        if ($location === '') {
            return CheckOutcome::down(__('HTTP :status without a Location header', ['status' => $status]), $milliseconds, $status);
        }

        // null: a relative reference ("/nl/"); false: no URL at all.
        $parsed = parse_url($location, PHP_URL_HOST);

        if ($parsed === false) {
            return CheckOutcome::down(__('Invalid Location header: :location', ['location' => Str::limit($location, 200)]), $milliseconds, $status);
        }

        $target = rtrim(mb_strtolower($parsed ?? Check::hostOf($check->target)), '.');

        return $target === $host
            ? CheckOutcome::up($milliseconds, $status)
            : CheckOutcome::down(__('Redirects to :target, expected :host', ['target' => $target, 'host' => $host]), $milliseconds, $status);
    }

    public function runOne(Check $check): CheckOutcome
    {
        if ($check->type->isHttp()) {
            return $this->runHttp(collect([$check]))[$check->id];
        }

        $timeout = (float) min(5, (int) config('herdbook.checks.timeout_seconds'));

        try {
            return match ($check->type) {
                CheckType::Tcp => $this->runTcp($check, $timeout),
                CheckType::Tls => $this->runTls($check, $timeout),
                CheckType::Dns => $this->runDns($check),
                CheckType::Ping => CheckOutcome::up($this->probe->ping($check->target, $timeout)),
                default => CheckOutcome::down(__('Unknown type of check.')),
            };
        } catch (ProbeFailed $failure) {
            return CheckOutcome::down($failure->getMessage());
        } catch (Throwable $exception) {
            // A time-out of a process, an odd target: one check down, never a lost batch.
            report($exception);

            return CheckOutcome::down(__('The check itself failed.'));
        }
    }

    private function runTcp(Check $check, float $timeout): CheckOutcome
    {
        [$host, $port] = $this->hostAndPort($check->target, null);

        if ($port === null) {
            return CheckOutcome::down(__('No port in the target (:format).', ['format' => 'host:'.__('port')]));
        }

        return CheckOutcome::up($this->probe->tcp($host, $port, $timeout));
    }

    private function runTls(Check $check, float $timeout): CheckOutcome
    {
        [$host, $port] = $this->hostAndPort($check->target, 443);
        $expires = $this->probe->certificateExpiry($host, $port, $timeout);
        $daysLeft = (int) floor(CarbonImmutable::now()->diffInDays($expires, false));
        $warnDays = (int) $check->setting('warn_days', 14);
        $date = $expires->timezone(config('herdbook.display_timezone'))->translatedFormat(__('formats.date'));

        if ($daysLeft < 0) {
            return CheckOutcome::down(__('Certificate expired on :date', ['date' => $date]));
        }

        return $daysLeft < $warnDays
            ? CheckOutcome::down(trans_choice('Certificate expires in :count day (:date)|Certificate expires in :count days (:date)', $daysLeft, ['date' => $date]))
            : CheckOutcome::up();
    }

    private function runDns(Check $check): CheckOutcome
    {
        $addresses = array_map(self::normalize(...), $this->probe->resolve($check->target));
        $expected = array_map(self::normalize(...), $this->dnsExpectation->for($check));

        if ($expected === []) {
            return CheckOutcome::up();
        }

        // Per address family: without an expected IPv6 address, AAAA records
        // are not this check's business (and the other way around).
        $families = array_unique(array_map(self::family(...), $expected));
        $unexpected = array_values(array_filter(
            array_diff($addresses, $expected),
            fn (string $address): bool => in_array(self::family($address), $families, true),
        ));

        return $unexpected === []
            ? CheckOutcome::up()
            : CheckOutcome::down(__('Points to :addresses, expected :expected', ['addresses' => implode(', ', $unexpected), 'expected' => implode(', ', $expected)]));
    }

    /**
     * One notation per address: "2001:DB8:0::1" and "2001:db8::1" are equal.
     */
    private static function normalize(string $address): string
    {
        $binary = @inet_pton($address);

        return $binary === false ? $address : (string) inet_ntop($binary);
    }

    private static function family(string $address): int
    {
        return str_contains($address, ':') ? 6 : 4;
    }

    /**
     * "host:port", "[ipv6]:port", or a bare host / IPv6 address.
     *
     * @return array{string, ?int}
     */
    private function hostAndPort(string $target, ?int $defaultPort): array
    {
        if (preg_match('/^\[(.+)\]:(\d+)$/', $target, $matches) === 1) {
            return [$matches[1], (int) $matches[2]];
        }

        if (substr_count($target, ':') === 1 && preg_match('/^(.+):(\d+)$/', $target, $matches) === 1) {
            return [$matches[1], (int) $matches[2]];
        }

        return [trim($target, '[]'), $defaultPort];
    }

    private function shortReason(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'timed out') => __('timed out'),
            str_contains($message, 'Could not resolve host') => __('host not found'),
            str_contains($message, 'Connection refused') => __('connection refused'),
            str_contains($message, 'SSL') => __('TLS error'),
            default => __('connection error'),
        };
    }
}
