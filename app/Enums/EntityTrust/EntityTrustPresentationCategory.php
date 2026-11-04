<?php

namespace App\Enums\EntityTrust;

/**
 * Merchant-safe presentation category for a single EntityTrustFailureReason.
 *
 * Used exclusively by the R2b-2 merchant UI to decide copy, severity and which
 * follow-up action the merchant is offered. NOT used by the R2b-1 backend.
 */
enum EntityTrustPresentationCategory: string
{
    case Success = 'success';
    case Actionable = 'actionable';
    case RelinkRequired = 'relink_required';
    case StaleReview = 'stale_review';
    case NoSafeCandidate = 'no_safe_candidate';
    case IdentityConflict = 'identity_conflict';
    case Security = 'security';
    case RemoteVerificationFailure = 'remote_verification_failure';
}
