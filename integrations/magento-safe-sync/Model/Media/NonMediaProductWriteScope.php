<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model\Media;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

final class NonMediaProductWriteScope implements ResetAfterRequestInterface
{
    private ?int $logicalEntityId = null;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runForLogicalEntity(int $logicalEntityId, callable $callback): mixed
    {
        if ($logicalEntityId <= 0) {
            throw new \RuntimeException('safe_sync_non_media_scope_invalid_logical_entity_id');
        }

        if ($this->logicalEntityId !== null) {
            throw new \RuntimeException('safe_sync_non_media_scope_reentry_forbidden');
        }

        $this->logicalEntityId = $logicalEntityId;

        try {
            return $callback();
        } finally {
            $this->logicalEntityId = null;
        }
    }

    public function isActiveForLogicalEntity(int $logicalEntityId): bool
    {
        return $this->logicalEntityId !== null && $this->logicalEntityId === $logicalEntityId;
    }

    public function _resetState(): void
    {
        $this->logicalEntityId = null;
    }
}
