<?php

namespace App\Support\Workspace\Rbac\Exceptions;

use RuntimeException;

final class WorkspaceAccessLockoutException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Workspace access mutation would remove the last effective manage_workspace_access holder.');
    }

    public function userMessageKey(): string
    {
        return 'lockout';
    }
}
