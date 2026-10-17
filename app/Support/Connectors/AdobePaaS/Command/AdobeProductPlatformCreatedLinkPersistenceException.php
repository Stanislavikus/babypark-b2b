<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use RuntimeException;

final class AdobeProductPlatformCreatedLinkPersistenceException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
