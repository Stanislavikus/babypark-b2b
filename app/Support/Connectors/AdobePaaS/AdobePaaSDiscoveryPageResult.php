<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\ConnectorDiscoveryAttemptResult;

final readonly class AdobePaaSDiscoveryPageResult
{
    private function __construct(
        public ?AdobePaaSDiscoveryPage $page,
        public ?ConnectorDiscoveryAttemptResult $failure,
    ) {}

    public static function success(AdobePaaSDiscoveryPage $page): self
    {
        return new self($page, null);
    }

    public static function failure(ConnectorDiscoveryAttemptResult $failure): self
    {
        if ($failure->succeeded) {
            throw new \InvalidArgumentException('Failure result must not be a success.');
        }

        return new self(null, $failure);
    }
}
