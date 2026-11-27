<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model\Connection;

use B2BPlatform\MagentoSafeSync\Model\ProductEntityManagerCallbackBridge;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

final class ConnectionQuarantine
{
    public function __construct(
        private readonly ProductEntityManagerCallbackBridge $callbackBridge,
    ) {}

    public function isSupported(object $connection): bool
    {
        return $connection instanceof ResetAfterRequestInterface
            && method_exists($connection, '_resetState');
    }

    /**
     * @return array{success:bool,callback_clear_failed:bool}
     */
    public function quarantine(object $connection): array
    {
        $callbackClearFailed = false;

        try {
            $this->callbackBridge->clearPendingProductCallbacksForConnection($connection);
        } catch (\Throwable) {
            $callbackClearFailed = true;
        }

        if (! $this->isSupported($connection)) {
            return [
                'success' => false,
                'callback_clear_failed' => $callbackClearFailed,
            ];
        }

        try {
            $connection->_resetState();
        } catch (\Throwable) {
            return [
                'success' => false,
                'callback_clear_failed' => $callbackClearFailed,
            ];
        }

        return [
            'success' => ! $callbackClearFailed,
            'callback_clear_failed' => $callbackClearFailed,
        ];
    }
}
