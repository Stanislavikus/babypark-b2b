<?php

namespace App\Support\Connectors\AdobePaaS;

final readonly class AdobeAttributeMetadata
{
    /**
     * @param  array<string, string>  $options  option value => label
     */
    public function __construct(
        public int $attributeId,
        public string $code,
        public string $frontendInput,
        public string $scope,
        public array $options,
        public ?string $defaultFrontendLabel = null,
    ) {}
}
