<?php

namespace App\Support\Sync\EntityTrust\Exceptions;

use App\Enums\EntityTrust\EntityTrustFailureReason;
use RuntimeException;

final class EntityTrustException extends RuntimeException
{
    public function __construct(
        public readonly EntityTrustFailureReason $reason,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $reason->value, 0, $previous);
    }

    public static function unauthorized(): self
    {
        return new self(EntityTrustFailureReason::Unauthorized);
    }

    public static function candidateNotFound(): self
    {
        return new self(EntityTrustFailureReason::CandidateNotFound);
    }

    public static function candidateUntrusted(): self
    {
        return new self(EntityTrustFailureReason::CandidateUntrusted);
    }

    public static function remoteTypeMismatch(): self
    {
        return new self(EntityTrustFailureReason::RemoteTypeMismatch);
    }

    public static function remoteChangedSinceReview(): self
    {
        return new self(EntityTrustFailureReason::RemoteChangedSinceReview);
    }

    public static function localChangedSinceReview(): self
    {
        return new self(EntityTrustFailureReason::LocalChangedSinceReview);
    }

    public static function linkCollision(): self
    {
        return new self(EntityTrustFailureReason::LinkCollision);
    }

    public static function ambiguousExistingLink(): self
    {
        return new self(EntityTrustFailureReason::AmbiguousExistingLink);
    }

    public static function confirmationExpiredOrInvalid(): self
    {
        return new self(EntityTrustFailureReason::ConfirmationExpiredOrInvalid);
    }

    public static function accountConfigurationNotCurrent(): self
    {
        return new self(EntityTrustFailureReason::AccountConfigurationNotCurrent);
    }

    public static function safeSyncFailure(?\Throwable $previous = null): self
    {
        return new self(EntityTrustFailureReason::SafeSyncFailure, previous: $previous);
    }

    public static function invalidReviewEvidence(): self
    {
        return new self(EntityTrustFailureReason::ConfirmationExpiredOrInvalid);
    }

    public static function differentEntityWithoutRelink(): self
    {
        return new self(EntityTrustFailureReason::DifferentEntityWithoutRelink);
    }

    public static function relinkReviewRequired(): self
    {
        return new self(EntityTrustFailureReason::RelinkReviewRequired);
    }
}
