<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

final class AdobeSafeSyncContract
{
    /** Compatibility epoch. Additive component releases retain this value. */
    public const string CONTRACT_VERSION = 'stage3e-r1';

    public const string PRODUCT_VERIFICATION_READ_FAMILY = 'entity_bound_product_read';

    public const string SIMPLE_PRODUCT_WRITE_FAMILY = 'entity_bound_simple_product_write';

    public const string SIMPLE_PRODUCT_WRITE_MINIMUM_MODULE_VERSION = '0.2.1';

    public const int HANDSHAKE_MAX_RESPONSE_BYTES = 16 * 1024;

    public const int PRODUCT_READ_MAX_RESPONSE_BYTES = 64 * 1024;

    public const int SIMPLE_PRODUCT_WRITE_MAX_REQUEST_BYTES = 16 * 1024;

    public const int SIMPLE_PRODUCT_WRITE_MAX_RESPONSE_BYTES = 16 * 1024;

    private function __construct() {}
}
