<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model;

final class SafeSyncContract
{
    public const MODULE_NAME = 'B2BPlatform_MagentoSafeSync';

    /** Compatibility epoch. Additive component releases retain this value. */
    public const CONTRACT_VERSION = 'stage3e-r1';

    public const PRODUCT_VERIFICATION_READ_FAMILY = 'entity_bound_product_read';

    public const SIMPLE_PRODUCT_WRITE_FAMILY = 'entity_bound_simple_product_write';

    private function __construct() {}
}
