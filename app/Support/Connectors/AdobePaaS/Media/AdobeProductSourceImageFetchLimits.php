<?php

namespace App\Support\Connectors\AdobePaaS\Media;

final class AdobeProductSourceImageFetchLimits
{
    public const float CONNECT_TIMEOUT_SECONDS = 5.0;

    public const float TOTAL_TIMEOUT_SECONDS = 20.0;

    public const int MAX_SOURCE_RESPONSE_BYTES = 10 * 1024 * 1024;
}
