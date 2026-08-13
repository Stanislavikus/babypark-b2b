<?php

namespace App\Support\Workspace\Rbac\Exceptions;

use RuntimeException;

final class WorkspaceAccessMutationRejectedException extends RuntimeException
{
    public static function foreignMembership(string $membershipId, string $workspaceId): self
    {
        return new self("Workspace membership {$membershipId} does not belong to workspace {$workspaceId}.");
    }

    public static function foreignRole(string $roleId, string $workspaceId): self
    {
        return new self("Workspace role {$roleId} does not belong to workspace {$workspaceId}.");
    }

    public static function roleStillAssigned(string $roleId): self
    {
        return new self("Workspace role {$roleId} is still assigned to one or more memberships.");
    }

    public static function unknownPermissionCodes(array $codes): self
    {
        return new self('Unknown workspace permission codes: '.implode(', ', $codes));
    }

    public static function forgedTemplateKey(): self
    {
        return new self('Merchant-created roles must not set template_key.');
    }
}
