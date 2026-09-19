<?php

namespace App\Support\Sync\FieldOptionMappingReadModel;

final readonly class FieldOptionMappingStaleCorrespondenceRow
{
    public function __construct(
        public string $fieldOptionMappingId,
        public ?string $externalOptionValue,
        public ?string $externalLabel,
    ) {}
}
