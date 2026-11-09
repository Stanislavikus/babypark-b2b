<?php

namespace App\Support\Workspace\Rbac\Exceptions;

use RuntimeException;

final class WorkspaceAccessMutationRejectedException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $messageKey,
    ) {
        parent::__construct($message);
    }

    public function userMessageKey(): string
    {
        return $this->messageKey;
    }

    public static function foreignMembership(string $membershipId, string $workspaceId): self
    {
        return new self(
            "Workspace membership {$membershipId} does not belong to workspace {$workspaceId}.",
            'foreign_target',
        );
    }

    public static function foreignRole(string $roleId, string $workspaceId): self
    {
        return new self(
            "Workspace role {$roleId} does not belong to workspace {$workspaceId}.",
            'foreign_target',
        );
    }

    public static function roleStillAssigned(string $roleId): self
    {
        return new self(
            "Workspace role {$roleId} is still assigned to one or more memberships.",
            'role_still_assigned',
        );
    }

    /**
     * @param  list<string>  $codes
     */
    public static function unknownPermissionCodes(array $codes): self
    {
        return new self(
            'Unknown workspace permission codes: '.implode(', ', $codes),
            'unknown_permission',
        );
    }

    /**
     * @param  list<string>  $codes
     */
    public static function missingCanonicalPermissionRows(array $codes): self
    {
        return new self(
            'Missing canonical workspace permission catalogue rows: '.implode(', ', $codes),
            'unknown_permission',
        );
    }

    public static function forgedTemplateKey(): self
    {
        return new self(
            'Merchant-created roles must not set template_key.',
            'unknown_permission',
        );
    }
}
