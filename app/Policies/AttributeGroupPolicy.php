<?php

namespace App\Policies;

use App\Models\AttributeGroup;
use App\Models\User;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;

final class AttributeGroupPolicy
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows(
            $user,
            $this->workspaceContext->current(),
            WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE,
        );
    }

    public function view(User $user, AttributeGroup $group): bool
    {
        return $this->authorization->allows($user, $group->workspace, WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows(
            $user,
            $this->workspaceContext->current(),
            WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE,
        );
    }

    public function update(User $user, AttributeGroup $group): bool
    {
        return $this->authorization->allows($user, $group->workspace, WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE);
    }

    public function delete(User $user, AttributeGroup $group): bool
    {
        return false;
    }

    public function restore(User $user, AttributeGroup $group): bool
    {
        return false;
    }

    public function forceDelete(User $user, AttributeGroup $group): bool
    {
        return false;
    }
}
