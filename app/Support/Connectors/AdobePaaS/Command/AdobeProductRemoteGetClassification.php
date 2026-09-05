<?php

namespace App\Support\Connectors\AdobePaaS\Command;

enum AdobeProductRemoteGetClassification: string
{
    case Found = 'found';
    case TrustedKnownMissing = 'trusted_known_missing';
    case UntrustedOrFailed = 'untrusted_or_failed';
}
