<?php

namespace App\Jobs\Connectors;

final class ConnectorConnectionCheckJobExecutionException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Connection-check job execution failed.');
    }
}
