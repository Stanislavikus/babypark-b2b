<?php

namespace App\Support\Connectors\AdobePaaS\Media;

final readonly class AdobeProductSourceImageValidationResult
{
    private function __construct(
        public bool $accepted,
        public ?AdobeProductVerifiedSourceImage $verifiedImage = null,
        public string $reasonCode = '',
    ) {}

    public static function accepted(AdobeProductVerifiedSourceImage $verifiedImage): self
    {
        return new self(true, $verifiedImage);
    }

    public static function rejected(string $reasonCode): self
    {
        return new self(false, reasonCode: $reasonCode);
    }
}
