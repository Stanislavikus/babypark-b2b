<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class SyncConfigurationNotFoundException extends RuntimeException
{
    public static function forId(string $syncConfigurationId): self
    {
        return new self(sprintf('Sync configuration [%s] was not found.', $syncConfigurationId));
    }
}
