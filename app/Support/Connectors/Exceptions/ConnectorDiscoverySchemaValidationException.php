<?php

namespace App\Support\Connectors\Exceptions;

use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Enums\ConnectorDiscoverySchemaValidationReason;
use RuntimeException;

final class ConnectorDiscoverySchemaValidationException extends RuntimeException
{
    public readonly ConnectorDiscoverySchemaValidationReason $reason;

    public readonly string $path;

    private function __construct(
        ConnectorDiscoverySchemaValidationReason $reason,
        string $path,
    ) {
        parent::__construct($reason->value.' at '.$path);
        $this->reason = $reason;
        $this->path = $path;
    }

    public static function at(
        ConnectorDiscoverySchemaValidationReason $reason,
        string $path,
    ): self {
        if (! preg_match(
            '/^(?:\$|[a-z_][a-z0-9_]*(?:\[\d+\])?(?:\.[a-z_][a-z0-9_]*)*)$/',
            $path,
        )) {
            throw new \LogicException('Invalid structural path: '.$path);
        }

        return new self($reason, $path);
    }

    public function errorCode(): ConnectorDiscoveryRunErrorCode
    {
        return ConnectorDiscoveryRunErrorCode::DiscoverySchemaValidationFailed;
    }
}
