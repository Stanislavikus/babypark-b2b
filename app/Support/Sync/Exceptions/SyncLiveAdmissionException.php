<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class SyncLiveAdmissionException extends RuntimeException
{
    private const string RUNTIME_NOT_READY_MESSAGE = 'Connector account is not ready for live execution right now.';

    public static function notAuthorized(): self
    {
        return new self('Live execution is not authorized for this workspace.');
    }

    public static function configurationNotEnabled(string $configurationId): self
    {
        return new self("Sync configuration '{$configurationId}' is not enabled for live execution.");
    }

    public static function operationNotEnabled(string $operation): self
    {
        return new self("Semantic operation '{$operation}' is not enabled on this configuration.");
    }

    public static function operationNotSupported(): self
    {
        return new self('Live execution is not supported for this configuration.');
    }

    public static function configurationRevisionChanged(): self
    {
        return new self('Sync configuration changed after the receive proposal was issued.');
    }

    public static function activeRunExists(string $configurationId): self
    {
        return new self("An active sync run already exists for configuration '{$configurationId}'.");
    }

    public static function attributeSetUnconfigured(): self
    {
        return new self('Connector execution configuration is missing attribute_set_id.');
    }

    public static function accountNotEnabled(string $connectorAccountId): self
    {
        return new self("Connector account '{$connectorAccountId}' is not enabled.");
    }

    public static function previewEvidenceMissing(): self
    {
        return new self('A completed current-revision preview run is required before live execution.');
    }

    public static function runtimeNotReady(): self
    {
        return new self(self::RUNTIME_NOT_READY_MESSAGE);
    }

    public function isRuntimeNotReady(): bool
    {
        return $this->getMessage() === self::RUNTIME_NOT_READY_MESSAGE;
    }

    public static function dispatchFailed(?\Throwable $previous = null): self
    {
        return new self('Live run job dispatch failed after admission.', previous: $previous);
    }

    public static function unsafeTimingConfiguration(): self
    {
        return new self('Live execution timing configuration is unsafe and cannot admit runs.');
    }
}
