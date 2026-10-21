<?php

namespace App\Enums\EntityTrust;

enum EntityTrustReadinessStatus: string
{
    case InitialLinkRequired = 'initial_link_required';
    case ReconfirmationRequired = 'reconfirmation_required';
    case RelinkReviewRequired = 'relink_review_required';
    case AlreadyConfirmed = 'already_confirmed';
    case NoAction = 'no_action';
}
