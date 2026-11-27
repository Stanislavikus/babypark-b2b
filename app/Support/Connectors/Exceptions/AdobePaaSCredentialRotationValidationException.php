<?php

namespace App\Support\Connectors\Exceptions;

use App\Support\Connectors\ConnectorConnectionCheckResult;
use RuntimeException;

final class AdobePaaSCredentialRotationValidationException extends RuntimeException
{
    public function __construct(public readonly ConnectorConnectionCheckResult $result)
    {
        parent::__construct('Replacement credentials did not pass the Adobe Commerce Product READ baseline.');
    }
}
