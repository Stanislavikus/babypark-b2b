<?php

namespace App\Support\Connectors\Transport;

final class ConnectorTransportException extends \RuntimeException
{
    public function __construct(
        public readonly TransportFailureReason $reason,
        public readonly ?TimeoutPhase $timeoutPhase = null,
    ) {
        parent::__construct('Connector transport failed.');
    }
}
