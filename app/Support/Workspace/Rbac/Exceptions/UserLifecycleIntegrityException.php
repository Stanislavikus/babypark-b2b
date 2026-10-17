<?php

namespace App\Support\Workspace\Rbac\Exceptions;

use RuntimeException;

final class UserLifecycleIntegrityException extends RuntimeException
{
    public function __construct(string $message = 'User lifecycle mutation would violate workspace access integrity.')
    {
        parent::__construct($message);
    }
}
