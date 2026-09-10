<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model;

use RuntimeException;

final class SafeSyncReadException extends RuntimeException
{
    public static function invalidLogicalEntityId(): self
    {
        return new self('safe_sync_invalid_logical_entity_id');
    }

    public static function invalidExpectedSku(): self
    {
        return new self('safe_sync_invalid_expected_sku');
    }

    public static function entityMissing(): self
    {
        return new self('safe_sync_entity_missing');
    }

    public static function identityMismatch(): self
    {
        return new self('safe_sync_identity_mismatch');
    }

    public static function skuMismatch(): self
    {
        return new self('safe_sync_sku_mismatch');
    }

    public static function ambiguousSku(): self
    {
        return new self('safe_sync_ambiguous_sku');
    }

    public static function causalReadUnavailable(?\Throwable $previous = null): self
    {
        return new self('safe_sync_causal_read_unavailable', 0, $previous);
    }

    public static function wsrepRestoreFailed(?\Throwable $previous = null): self
    {
        return new self('safe_sync_wsrep_restore_failed', 0, $previous);
    }
}
