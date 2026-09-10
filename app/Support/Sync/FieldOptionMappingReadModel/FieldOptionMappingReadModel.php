<?php

namespace App\Support\Sync\FieldOptionMappingReadModel;

final readonly class FieldOptionMappingReadModel
{
    /**
     * @param  list<FieldOptionMappingNormalRow>  $normalRows
     * @param  list<FieldOptionMappingStaleCorrespondenceRow>  $staleCorrespondenceRows
     * @param  list<ExternalOptionChoice>  $externalChoices
     */
    public function __construct(
        public string $fieldMappingId,
        public string $fieldBindingId,
        public string $internalFieldLabel,
        public string $externalFieldKey,
        public ?string $externalFieldLabel,
        public string $platformName,
        public string $accountName,
        public bool $externalChoicesResolvable,
        public bool $eligible,
        public array $normalRows,
        public array $staleCorrespondenceRows,
        public array $externalChoices,
    ) {}
}
