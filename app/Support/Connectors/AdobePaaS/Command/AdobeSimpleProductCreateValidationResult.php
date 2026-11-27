<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeSimpleProductCreateValidationResult
{
    /**
     * @param  list<string>  $attributeCodes
     */
    public function __construct(
        public bool $ready,
        public string $reasonCode,
        public array $attributeCodes = [],
    ) {}

    public static function ready(): self
    {
        return new self(true, 'adobe_create_preflight_ready');
    }

    /** @param list<string> $attributeCodes */
    public static function blocked(string $reasonCode, array $attributeCodes = []): self
    {
        sort($attributeCodes, SORT_STRING);

        return new self(false, $reasonCode, array_values(array_unique($attributeCodes)));
    }
}
