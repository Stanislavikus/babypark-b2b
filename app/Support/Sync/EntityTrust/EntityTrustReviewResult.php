<?php

namespace App\Support\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustConfirmationMode;
use App\Enums\EntityTrust\EntityTrustFailureReason;

final readonly class EntityTrustReviewResult
{
    /**
     * @param  list<EntityTrustSubjectReview>  $subjects
     * @param  list<string>  $extraRemoteChildSkus
     */
    public function __construct(
        public EntityTrustFailureReason $status,
        public EntityTrustConfirmationMode $mode,
        public string $productId,
        public string $syncConfigurationId,
        public string $configurationRevision,
        public array $subjects,
        public ?string $reviewToken = null,
        public array $extraRemoteChildSkus = [],
    ) {}

    public function isReadyForConfirmation(): bool
    {
        return $this->status === EntityTrustFailureReason::ReadyForConfirmation
            && $this->reviewToken !== null;
    }
}
