<?php

namespace App\Support\Workspace\Rbac\Exceptions;

use RuntimeException;

final class WorkspaceRbacLegacyBackfillConflictException extends RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
