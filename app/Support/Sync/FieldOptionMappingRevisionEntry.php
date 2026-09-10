<?php

namespace App\Support\Sync;

final readonly class FieldOptionMappingRevisionEntry
{
    public function __construct(
        public string $internalOptionKey,
        public string $externalOptionValue,
    ) {}

    /**
     * @return array{internal_option_key: string, external_option_value: string}
     */
    public function toRevisionArray(): array
    {
        return [
            'internal_option_key' => $this->internalOptionKey,
            'external_option_value' => $this->externalOptionValue,
        ];
    }
}
