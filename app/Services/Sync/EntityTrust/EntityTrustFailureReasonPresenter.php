<?php

namespace App\Services\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustFailureReason;
use App\Enums\EntityTrust\EntityTrustPresentationCategory;

/**
 * Merchant-safe, exhaustive presenter for every current EntityTrustFailureReason.
 *
 * The presenter is a frozen mapping; there is NO default branch. The regression
 * test iterates EntityTrustFailureReason::cases() and asserts every case is
 * covered here. Adding a new enum case must produce a mapping update in the
 * same PR.
 */
final class EntityTrustFailureReasonPresenter
{
    /**
     * @return array{
     *     category: EntityTrustPresentationCategory,
     *     label_key: string,
     *     explanation_key: string,
     *     available_action: string,
     * }
     */
    public function present(EntityTrustFailureReason $reason): array
    {
        return self::map()[$reason];
    }

    public function category(EntityTrustFailureReason $reason): EntityTrustPresentationCategory
    {
        return self::map()[$reason]['category'];
    }

    public function availableAction(EntityTrustFailureReason $reason): string
    {
        return self::map()[$reason]['available_action'];
    }

    public function isConfirmationResult(EntityTrustFailureReason $reason): bool
    {
        return $reason === EntityTrustFailureReason::ConfirmationCompleted
            || $reason === EntityTrustFailureReason::RelinkCompleted
            || $reason === EntityTrustFailureReason::AlreadyConfirmed;
    }

    public function isStaleReviewOutcome(EntityTrustFailureReason $reason): bool
    {
        return $this->category($reason) === EntityTrustPresentationCategory::StaleReview;
    }

    /**
     * Merchant-readable copy + action descriptor per enum case.
     *
     * Available actions are intentionally short tags consumed by the UI layer:
     *   - none                 → nothing to offer
     *   - review               → start a fresh review
     *   - relink               → start a fresh explicit relink review
     *   - resolve_setup        → guide merchant to settings/setup
     *   - retry_review         → safe re-review / re-verify
     *   - contact_support      → out of band; merchant-safe
     *
     * Implemented as a method (not a class const) so enum-case keys are
     * supported by PHP at runtime. A `const array` with enum-case keys
     * coerces keys to integer offsets in PHP 8.5, breaking lookups.
     *
     * @return array<EntityTrustFailureReason, array{category: EntityTrustPresentationCategory, label_key: string, explanation_key: string, available_action: string}>
     */
    private static function map(): array
    {
        return [
            EntityTrustFailureReason::Unauthorized => [
                'category' => EntityTrustPresentationCategory::Security,
                'label_key' => 'entity_trust.failure.unauthorized.label',
                'explanation_key' => 'entity_trust.failure.unauthorized.explanation',
                'available_action' => 'none',
            ],
            EntityTrustFailureReason::ReadyForConfirmation => [
                'category' => EntityTrustPresentationCategory::Actionable,
                'label_key' => 'entity_trust.failure.ready_for_confirmation.label',
                'explanation_key' => 'entity_trust.failure.ready_for_confirmation.explanation',
                'available_action' => 'review',
            ],
            EntityTrustFailureReason::AlreadyConfirmed => [
                'category' => EntityTrustPresentationCategory::Success,
                'label_key' => 'entity_trust.failure.already_confirmed.label',
                'explanation_key' => 'entity_trust.failure.already_confirmed.explanation',
                'available_action' => 'none',
            ],
            EntityTrustFailureReason::ConfirmationCompleted => [
                'category' => EntityTrustPresentationCategory::Success,
                'label_key' => 'entity_trust.failure.confirmation_completed.label',
                'explanation_key' => 'entity_trust.failure.confirmation_completed.explanation',
                'available_action' => 'none',
            ],
            EntityTrustFailureReason::RelinkReviewRequired => [
                'category' => EntityTrustPresentationCategory::RelinkRequired,
                'label_key' => 'entity_trust.failure.relink_review_required.label',
                'explanation_key' => 'entity_trust.failure.relink_review_required.explanation',
                'available_action' => 'relink',
            ],
            EntityTrustFailureReason::RelinkCompleted => [
                'category' => EntityTrustPresentationCategory::Success,
                'label_key' => 'entity_trust.failure.relink_completed.label',
                'explanation_key' => 'entity_trust.failure.relink_completed.explanation',
                'available_action' => 'none',
            ],
            EntityTrustFailureReason::CandidateNotFound => [
                'category' => EntityTrustPresentationCategory::NoSafeCandidate,
                'label_key' => 'entity_trust.failure.candidate_not_found.label',
                'explanation_key' => 'entity_trust.failure.candidate_not_found.explanation',
                'available_action' => 'none',
            ],
            EntityTrustFailureReason::CandidateUntrusted => [
                'category' => EntityTrustPresentationCategory::NoSafeCandidate,
                'label_key' => 'entity_trust.failure.candidate_untrusted.label',
                'explanation_key' => 'entity_trust.failure.candidate_untrusted.explanation',
                'available_action' => 'none',
            ],
            EntityTrustFailureReason::RemoteTypeMismatch => [
                'category' => EntityTrustPresentationCategory::NoSafeCandidate,
                'label_key' => 'entity_trust.failure.remote_type_mismatch.label',
                'explanation_key' => 'entity_trust.failure.remote_type_mismatch.explanation',
                'available_action' => 'none',
            ],
            EntityTrustFailureReason::RemoteChangedSinceReview => [
                'category' => EntityTrustPresentationCategory::StaleReview,
                'label_key' => 'entity_trust.failure.remote_changed_since_review.label',
                'explanation_key' => 'entity_trust.failure.remote_changed_since_review.explanation',
                'available_action' => 'retry_review',
            ],
            EntityTrustFailureReason::LocalChangedSinceReview => [
                'category' => EntityTrustPresentationCategory::StaleReview,
                'label_key' => 'entity_trust.failure.local_changed_since_review.label',
                'explanation_key' => 'entity_trust.failure.local_changed_since_review.explanation',
                'available_action' => 'retry_review',
            ],
            EntityTrustFailureReason::LinkCollision => [
                'category' => EntityTrustPresentationCategory::IdentityConflict,
                'label_key' => 'entity_trust.failure.link_collision.label',
                'explanation_key' => 'entity_trust.failure.link_collision.explanation',
                'available_action' => 'contact_support',
            ],
            EntityTrustFailureReason::AmbiguousExistingLink => [
                'category' => EntityTrustPresentationCategory::IdentityConflict,
                'label_key' => 'entity_trust.failure.ambiguous_existing_link.label',
                'explanation_key' => 'entity_trust.failure.ambiguous_existing_link.explanation',
                'available_action' => 'contact_support',
            ],
            EntityTrustFailureReason::ConfirmationExpiredOrInvalid => [
                'category' => EntityTrustPresentationCategory::StaleReview,
                'label_key' => 'entity_trust.failure.confirmation_expired_or_invalid.label',
                'explanation_key' => 'entity_trust.failure.confirmation_expired_or_invalid.explanation',
                'available_action' => 'retry_review',
            ],
            EntityTrustFailureReason::AccountConfigurationNotCurrent => [
                'category' => EntityTrustPresentationCategory::StaleReview,
                'label_key' => 'entity_trust.failure.account_configuration_not_current.label',
                'explanation_key' => 'entity_trust.failure.account_configuration_not_current.explanation',
                'available_action' => 'resolve_setup',
            ],
            EntityTrustFailureReason::SafeSyncFailure => [
                'category' => EntityTrustPresentationCategory::RemoteVerificationFailure,
                'label_key' => 'entity_trust.failure.safe_sync_failure.label',
                'explanation_key' => 'entity_trust.failure.safe_sync_failure.explanation',
                'available_action' => 'retry_review',
            ],
            EntityTrustFailureReason::InvalidReviewEvidence => [
                'category' => EntityTrustPresentationCategory::Security,
                'label_key' => 'entity_trust.failure.invalid_review_evidence.label',
                'explanation_key' => 'entity_trust.failure.invalid_review_evidence.explanation',
                'available_action' => 'retry_review',
            ],
            EntityTrustFailureReason::DifferentEntityWithoutRelink => [
                'category' => EntityTrustPresentationCategory::RelinkRequired,
                'label_key' => 'entity_trust.failure.different_entity_without_relink.label',
                'explanation_key' => 'entity_trust.failure.different_entity_without_relink.explanation',
                'available_action' => 'relink',
            ],
            EntityTrustFailureReason::ReviewTargetMismatch => [
                'category' => EntityTrustPresentationCategory::StaleReview,
                'label_key' => 'entity_trust.failure.review_target_mismatch.label',
                'explanation_key' => 'entity_trust.failure.review_target_mismatch.explanation',
                'available_action' => 'resolve_setup',
            ],
        ];
    }
}
