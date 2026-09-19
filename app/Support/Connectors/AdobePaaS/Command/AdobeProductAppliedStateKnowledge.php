<?php

namespace App\Support\Connectors\AdobePaaS\Command;

enum AdobeProductAppliedStateKnowledge: string
{
    case KnownApplied = 'known_applied';
    case KnownNotApplied = 'known_not_applied';
    case UnknownOrAmbiguous = 'unknown_or_ambiguous';
}
