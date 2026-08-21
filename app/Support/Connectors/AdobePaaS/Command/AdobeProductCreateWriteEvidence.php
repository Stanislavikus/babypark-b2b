<?php

namespace App\Support\Connectors\AdobePaaS\Command;

enum AdobeProductCreateWriteEvidence: string
{
    case DefinitiveSuccess = 'definitive_success';
    case Inconclusive = 'inconclusive';
}
