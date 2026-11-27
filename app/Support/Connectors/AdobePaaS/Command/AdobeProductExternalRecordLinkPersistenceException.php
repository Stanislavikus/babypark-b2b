<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

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

    public static function ambiguousVariantIdentity(): self
    {
        return new self('Multiple variant-scoped ExternalRecordLink identities were found for the same subject.');
    }

    public static function ambiguousParentIdentity(): self
    {
        return new self('Multiple product-scoped ExternalRecordLink identities were found for the same subject.');
    }

    public static function productNotFound(): self
    {
        return new self('Product was not found for ExternalRecordLink persistence.');
    }

    public static function identityDriftDetected(): self
    {
        return new self('Trusted ExternalRecordLink identity drift detected during persistence.');
    }

    public static function merchantConfirmationRequired(): self
    {
        return new self('Merchant-confirmed ExternalRecordLink provenance is required before persistence.');
    }

    public static function connectorAccountNotFound(ModelNotFoundException $previous): self
    {
        return new self('ConnectorAccount was not found for ExternalRecordLink persistence.', 0, $previous);
    }

    public static function databaseFailure(Throwable $previous): self
    {
        return new self('ExternalRecordLink persistence failed due to a database error.', 0, $previous);
    }
}
