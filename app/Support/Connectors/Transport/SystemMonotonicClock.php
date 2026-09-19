<?php

namespace App\Support\Connectors\Transport;

final class SystemMonotonicClock implements MonotonicClock
{
    public function nowNanoseconds(): int
    {
        return hrtime(true);
    }
}
