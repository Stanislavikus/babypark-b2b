<?php

namespace App\Support\Workspace\Rbac\Exceptions;

use RuntimeException;

final class WorkspaceAccessUnauthorizedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Actor is not authorized to mutate workspace access in the target workspace.');
    }
}
