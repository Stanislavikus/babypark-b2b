<?php

namespace App\Support\Connectors\Transport;

final class TransportConfigurationException extends \RuntimeException
{
    public function __construct(
        public readonly TransportConfigurationFailureReason $reason,
    ) {
        parent::__construct('Connector transport configuration is invalid.');
    }
}
