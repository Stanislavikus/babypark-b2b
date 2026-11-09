<?php

namespace App\Jobs\Connectors;

use RuntimeException;

final class ConnectorDiscoveryRunJobExecutionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Connector discovery job execution failed.');
    }
}
