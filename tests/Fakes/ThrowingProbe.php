<?php

namespace Tests\Fakes;

use RuntimeException;

/**
 * A probe whose DNS lookup throws something unexpected (a process time-out).
 */
class ThrowingProbe extends FakeProbe
{
    public function resolve(string $name): array
    {
        throw new RuntimeException('Process timed out');
    }
}
