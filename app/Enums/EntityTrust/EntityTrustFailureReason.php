<?php

namespace App\Enums\EntityTrust;

enum EntityTrustFailureReason: string
{
    case Unauthorized = 'unauthorized';
    case ReadyForConfirmation = 'ready_for_confirmation';
    case AlreadyConfirmed = 'already_confirmed';
    case ConfirmationCompleted = 'confirmation_completed';
    case RelinkReviewRequired = 'relink_review_required';
    case RelinkCompleted = 'relink_completed';
    case CandidateNotFound = 'candidate_not_found';
    case CandidateUntrusted = 'candidate_untrusted';
    case RemoteTypeMismatch = 'remote_type_mismatch';
    case RemoteChangedSinceReview = 'remote_changed_since_review';
    case LocalChangedSinceReview = 'local_changed_since_review';
    case LinkCollision = 'link_collision';
    case AmbiguousExistingLink = 'ambiguous_existing_link';
    case ConfirmationExpiredOrInvalid = 'confirmation_expired_or_invalid';
    case AccountConfigurationNotCurrent = 'account_configuration_not_current';
    case SafeSyncFailure = 'safe_sync_failure';
    case InvalidReviewEvidence = 'invalid_review_evidence';
    case DifferentEntityWithoutRelink = 'different_entity_without_relink';
    case ReviewTargetMismatch = 'review_target_mismatch';
}
