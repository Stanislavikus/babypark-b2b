<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

use App\Support\Connectors\ConnectorConnectionCheckResult;

final readonly class AdobeSafeSyncHandshakeProbeResult
{
    private function __construct(
        public ConnectorConnectionCheckResult $connectionResult,
        public ?AdobeSafeSyncHandshake $handshake,
    ) {}

    public static function succeeded(AdobeSafeSyncHandshake $handshake): self
    {
        return new self(ConnectorConnectionCheckResult::success(), $handshake);
    }

    public static function failed(ConnectorConnectionCheckResult $result): self
    {
        return new self($result, null);
    }
}
