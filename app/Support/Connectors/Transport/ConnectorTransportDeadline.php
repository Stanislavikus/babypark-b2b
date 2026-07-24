<?php

namespace App\Support\Connectors\Transport;

final readonly class ConnectorTransportDeadline
{
    public function __construct(
        private int $expiresAtNanoseconds,
        private MonotonicClock $clock,
    ) {}

    public static function fromLimits(ConnectorTransportLimits $limits, MonotonicClock $clock): self
    {
        $expiresAt = $clock->nowNanoseconds() + (int) round($limits->totalTimeoutSeconds * 1_000_000_000);

        return new self($expiresAt, $clock);
    }

    public function remainingSeconds(): float
    {
        $remainingNanoseconds = $this->expiresAtNanoseconds - $this->clock->nowNanoseconds();

        return max(0.0, $remainingNanoseconds / 1_000_000_000);
    }

    public function isExpired(): bool
    {
        return $this->clock->nowNanoseconds() >= $this->expiresAtNanoseconds;
    }
}
