<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

enum AdobeStage3EValidationOutcome: string
{
    case Pass = 'PASS';
    case Fail = 'FAIL';
    case Inconclusive = 'INCONCLUSIVE';
}
