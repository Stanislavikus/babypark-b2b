<?php

namespace App\Support\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustFailureReason;

final readonly class EntityTrustConfirmationResult
{
    /**
     * @param  list<string>  $persistedSubjectKeys
     */
    public function __construct(
        public EntityTrustFailureReason $status,
        public array $persistedSubjectKeys = [],
    ) {}

    public function isCompleted(): bool
    {
        return in_array($this->status, [
            EntityTrustFailureReason::ConfirmationCompleted,
            EntityTrustFailureReason::AlreadyConfirmed,
            EntityTrustFailureReason::RelinkCompleted,
        ], true);
    }
}
