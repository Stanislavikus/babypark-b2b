<?php

namespace App\Support\Workspace\Rbac\Exceptions;

use RuntimeException;

final class UserDeletionForbiddenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Users with workspace memberships cannot be hard-deleted. Deactivate the user instead.');
    }
}
