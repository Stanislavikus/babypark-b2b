<?php

namespace App\Policies;

use App\Models\ProductType;
use App\Models\User;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;

final class ProductTypePolicy
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

    public function view(User $user, ProductType $type): bool
    {
        return $this->authorization->allows($user, $type->workspace, WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows(
            $user,
            $this->workspaceContext->current(),
            WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE,
        );
    }

    public function update(User $user, ProductType $type): bool
    {
        if ($type->is_default) {
            return false;
        }

        return $this->authorization->allows($user, $type->workspace, WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE);
    }

    public function delete(User $user, ProductType $type): bool
    {
        return false;
    }

    public function restore(User $user, ProductType $type): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProductType $type): bool
    {
        return false;
    }
}
