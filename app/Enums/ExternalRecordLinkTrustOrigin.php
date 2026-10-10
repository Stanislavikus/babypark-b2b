<?php

namespace App\Enums;

enum ExternalRecordLinkTrustOrigin: string
{
    case MerchantConfirmed = 'merchant_confirmed';
    case PlatformCreated = 'platform_created';
}
