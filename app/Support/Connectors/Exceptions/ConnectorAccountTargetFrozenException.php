<?php

namespace App\Support\Connectors\Exceptions;

use RuntimeException;

final class ConnectorAccountTargetFrozenException extends RuntimeException
{
    public function __construct(
        string $message = 'This connection already has confirmed Magento product links. To connect it to another Magento target or store scope, create a new connection and confirm the links there.',
    ) {
        parent::__construct($message);
    }
}
