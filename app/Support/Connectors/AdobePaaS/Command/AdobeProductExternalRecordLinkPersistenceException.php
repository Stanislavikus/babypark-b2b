<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final class AdobeProductExternalRecordLinkPersistenceException extends \RuntimeException
{
    public static function collisionDetected(): self
    {
        return new self('ExternalRecordLink collision detected during persistence.');
    }

    public static function variantNotFound(): self
    {
        return new self('ProductVariant was not found for ExternalRecordLink persistence.');
    }
}
