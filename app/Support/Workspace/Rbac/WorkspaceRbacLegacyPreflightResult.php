<?php

namespace App\Support\Workspace\Rbac;

final readonly class WorkspaceRbacLegacyPreflightResult
{
    /**
     * @param  list<WorkspaceRbacLegacyPreflightFailureReason>  $failureReasons
     * @param  array<string, int>  $modelHasRolesModelTypeCounts
     * @param  array<string, int>  $modelHasPermissionsModelTypeCounts
     * @param  list<string>  $missingCanonicalPermissionCodes
     */
    public function __construct(
        public bool $isSafe,
        public array $failureReasons,
        public int $rolesCount,
        public int $modelHasRolesCount,
        public array $modelHasRolesModelTypeCounts,
        public int $modelHasPermissionsCount,
        public array $modelHasPermissionsModelTypeCounts,
        public int $roleHasPermissionsCount,
        public ?string $defaultWorkspaceId,
        public array $missingCanonicalPermissionCodes,
    ) {}

    /**
     * @return list<string>
     */
    public function failureReasonCodes(): array
    {
        return array_map(
            static fn (WorkspaceRbacLegacyPreflightFailureReason $reason): string => $reason->value,
            $this->failureReasons,
        );
    }
}
