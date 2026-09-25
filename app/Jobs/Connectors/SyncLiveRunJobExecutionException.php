<?php

namespace App\Jobs\Connectors;

use RuntimeException;

final class SyncLiveRunJobExecutionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Live sync execution failed.');
    }
}
