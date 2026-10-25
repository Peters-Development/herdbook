<?php

namespace Tests\Feature\Checks;

use App\Services\Checks\ConnectivityGuard;
use App\Services\Checks\NetworkProbe;
use App\Services\Checks\SocketProbe;
use Illuminate\Support\Facades\Cache;
use Tests\Fakes\FakeProbe;
use Tests\TestCase;

class NetworkProbeTest extends TestCase
{
    private FakeProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(NetworkProbe::class, $this->probe = new FakeProbe);
    }

    public function test_the_guard_caches_only_offline(): void
    {
        $guard = app(ConnectivityGuard::class);

        $this->assertTrue($guard->isOnline());
        $this->assertTrue($guard->isOnline());
        $this->assertCount(2, $this->probe->reachabilityChecks); // asked every time

        $this->probe->online = false;
        $this->assertFalse($guard->isOnline());
        $this->probe->online = true;
        $this->assertFalse($guard->isOnline()); // briefly cached

        Cache::flush();
        $this->assertTrue($guard->isOnline());
    }

    public function test_socket_probe_parsing(): void
    {
        $this->assertSame('[2001:db8::1]', SocketProbe::address('2001:db8::1'));
        $this->assertSame('app.example.net', SocketProbe::address('app.example.net'));
        $this->assertSame(['203.0.113.10', '2001:db8::1'], SocketProbe::addressesFromDig("alias.example.net.\n203.0.113.10\n2001:db8::1\n"));
        $this->assertSame(10, SocketProbe::milliseconds('64 bytes from 1.1.1.1: icmp_seq=1 ttl=57 time=9.81 ms'));
        $this->assertNull(SocketProbe::milliseconds('rubbish'));
    }
}
