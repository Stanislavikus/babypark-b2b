<?php

namespace App\Support\Connectors\Exceptions;

use RuntimeException;

final class AdobePaaSCredentialRotationConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Connector account changed while replacement credentials were being verified.');
    }
}
