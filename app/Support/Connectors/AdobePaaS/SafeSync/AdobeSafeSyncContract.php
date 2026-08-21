<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

final class AdobeSafeSyncContract
{
    public const string CONTRACT_VERSION = 'stage3e-r1';

    public const string PRODUCT_VERIFICATION_READ_FAMILY = 'entity_bound_product_read';

    public const int HANDSHAKE_MAX_RESPONSE_BYTES = 16 * 1024;

    public const int PRODUCT_READ_MAX_RESPONSE_BYTES = 64 * 1024;

    private function __construct() {}
}
