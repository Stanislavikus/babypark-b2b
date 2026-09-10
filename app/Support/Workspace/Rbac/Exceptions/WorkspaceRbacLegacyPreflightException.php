<?php

namespace App\Support\Workspace\Rbac\Exceptions;

use App\Support\Workspace\Rbac\WorkspaceRbacLegacyPreflightResult;
use RuntimeException;

final class WorkspaceRbacLegacyPreflightException extends RuntimeException
{
    public function __construct(
        public readonly WorkspaceRbacLegacyPreflightResult $result,
    ) {
        parent::__construct('Legacy RBAC preflight is not safe.');
    }

    public static function fromResult(WorkspaceRbacLegacyPreflightResult $result): self
    {
        return new self($result);
    }
}
