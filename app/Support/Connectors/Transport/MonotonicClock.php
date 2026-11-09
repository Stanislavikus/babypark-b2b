<?php

namespace App\Support\Connectors\Transport;

interface MonotonicClock
{
    public function nowNanoseconds(): int;
}
