<?php

namespace App\Support\Connectors\Transport;

final class DestinationRequestMismatch extends \LogicException
{
    public function __construct()
    {
        parent::__construct('Validated destination does not match the outbound request.');
    }
}
