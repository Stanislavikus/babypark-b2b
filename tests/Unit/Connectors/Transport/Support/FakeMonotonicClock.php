<?php

namespace Tests\Unit\Connectors\Transport\Support;

use App\Support\Connectors\Transport\MonotonicClock;

final class FakeMonotonicClock implements MonotonicClock
{
    private int $now;

    public function __construct(int $startNanoseconds = 0)
    {
        $this->now = $startNanoseconds;
    }

    public function nowNanoseconds(): int
    {
        return $this->now;
    }

    public function advanceNanoseconds(int $nanoseconds): void
    {
        $this->now += $nanoseconds;
    }

    public function advanceSeconds(float $seconds): void
    {
        $this->now += (int) round($seconds * 1_000_000_000);
    }
}
