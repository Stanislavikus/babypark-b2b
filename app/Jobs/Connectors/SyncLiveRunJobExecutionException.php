<?php

namespace App\Jobs\Connectors;

use RuntimeException;

final class SyncLiveRunJobExecutionException extends RuntimeException
{
    public static function executorNotImplemented(): self
    {
        return new self('Live sync executor is not implemented.');
    }
}
