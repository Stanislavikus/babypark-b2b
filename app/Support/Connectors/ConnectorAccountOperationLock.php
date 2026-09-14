<?php

namespace App\Support\Connectors;

final class ConnectorAccountOperationLock
{
    private const QUEUE_OVERLAP_PREFIX = 'laravel-queue-overlap:';

    public static function sharedKey(string $connectorAccountId): string
    {
        return "connector-account:{$connectorAccountId}";
    }

    public static function cacheKey(string $connectorAccountId): string
    {
        return self::QUEUE_OVERLAP_PREFIX.self::sharedKey($connectorAccountId);
    }
}
