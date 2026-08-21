<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model;

final class SafeSyncContract
{
    public const MODULE_NAME = 'B2BPlatform_MagentoSafeSync';
    public const CONTRACT_VERSION = 'stage3e-r1';
    public const PRODUCT_VERIFICATION_READ_FAMILY = 'entity_bound_product_read';

    private function __construct() {}
}
