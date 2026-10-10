<?php

namespace App\Support\Sync\Exceptions;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use RuntimeException;

final class UnsupportedSyncOperationException extends RuntimeException
{
    public static function forPair(SyncDataDomain $dataDomain, SyncSemanticOperation $operation): self
    {
        return new self(sprintf(
            'Connected runtime does not support [%s/%s].',
            $dataDomain->value,
            $operation->value,
        ));
    }
}
