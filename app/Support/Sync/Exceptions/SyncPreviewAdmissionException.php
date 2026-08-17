<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class SyncPreviewAdmissionException extends RuntimeException
{
    public static function notAuthorized(): self
    {
        return new self('Preview execution is not authorized for this workspace.');
    }

    public static function configurationNotEnabled(string $configurationId): self
    {
        return new self("Sync configuration '{$configurationId}' is not enabled for preview execution.");
    }

    public static function operationNotEnabled(string $operation): self
    {
        return new self("Semantic operation '{$operation}' is not enabled on this configuration.");
    }

    public static function operationNotSupported(): self
    {
        return new self('Preview execution is not supported for this configuration.');
    }

    public static function activeRunExists(string $configurationId): self
    {
        return new self("An active preview run already exists for configuration '{$configurationId}'.");
    }

    public static function attributeSetUnconfigured(): self
    {
        return new self('Connector execution configuration is missing attribute_set_id.');
    }

    public static function accountNotEnabled(string $connectorAccountId): self
    {
        return new self("Connector account '{$connectorAccountId}' is not enabled.");
    }

    public static function dispatchFailed(?\Throwable $previous = null): self
    {
        return new self('Preview run job dispatch failed after admission.', previous: $previous);
    }
}
