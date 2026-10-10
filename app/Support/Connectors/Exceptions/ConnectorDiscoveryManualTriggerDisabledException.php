<?php

namespace App\Support\Connectors\Exceptions;

final class ConnectorDiscoveryManualTriggerDisabledException extends \RuntimeException
{
    public function __construct(string $message = 'Manual discovery trigger is disabled.')
    {
        parent::__construct($message);
    }
}
