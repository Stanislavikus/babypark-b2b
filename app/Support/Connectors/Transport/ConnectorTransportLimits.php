<?php

namespace App\Support\Connectors\Transport;

final readonly class ConnectorTransportLimits
{
    public function __construct(
        public float $connectTimeoutSeconds,
        public float $totalTimeoutSeconds,
        public int $maxResponseBodyBytes,
    ) {
        if (! is_finite($connectTimeoutSeconds) || $connectTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('connectTimeoutSeconds must be a positive finite number.');
        }

        if (! is_finite($totalTimeoutSeconds) || $totalTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('totalTimeoutSeconds must be a positive finite number.');
        }

        if ($totalTimeoutSeconds < $connectTimeoutSeconds) {
            throw new \InvalidArgumentException('totalTimeoutSeconds must be greater than or equal to connectTimeoutSeconds.');
        }

        if ($maxResponseBodyBytes <= 0) {
            throw new \InvalidArgumentException('maxResponseBodyBytes must be a positive integer.');
        }
    }
}
