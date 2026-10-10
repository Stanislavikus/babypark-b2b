<?php

namespace App\Support\Connectors\Exceptions;

final class ConnectorDiscoverySourceResolutionException extends \RuntimeException
{
    public function __construct(
        public readonly ConnectorDiscoverySourceResolutionReason $reason,
        public readonly int $matchCount,
        string $message = 'Discovery schema source could not be resolved.',
    ) {
        parent::__construct($message);
    }
}
