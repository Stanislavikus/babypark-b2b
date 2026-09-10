<?php

namespace App\Support\Connectors\AdobePaaS\Media;

final class AdobeProductMediaApiLimits
{
    public const float CONNECT_TIMEOUT_SECONDS = 10.0;

    public const float TOTAL_TIMEOUT_SECONDS = 60.0;

    public const int MAX_PRODUCT_METADATA_RESPONSE_BYTES = 2 * 1024 * 1024;

    public const int MAX_INDIVIDUAL_MEDIA_GET_RESPONSE_BYTES = 16 * 1024 * 1024;

    public const int MAX_MUTATION_RESPONSE_BYTES = 2 * 1024 * 1024;

    public const int MAX_IMAGE_METADATA_ENTRIES = 50;
}
