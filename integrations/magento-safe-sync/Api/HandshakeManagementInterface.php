<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Api;

use B2BPlatform\MagentoSafeSync\Api\Data\HandshakeResponseInterface;

interface HandshakeManagementInterface
{
    /**
     * Returns machine-safe compatibility metadata for the Safe Sync module.
     */
    public function handshake(): HandshakeResponseInterface;
}
