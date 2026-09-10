<?php

namespace App\Support\Connectors\Exceptions;

final class ConnectorDiscoverySourceInvalidAfterReservationException extends \RuntimeException
{
    public function __construct(string $message = 'Discovery schema source became invalid after execution slot reservation.')
    {
        parent::__construct($message);
    }
}
