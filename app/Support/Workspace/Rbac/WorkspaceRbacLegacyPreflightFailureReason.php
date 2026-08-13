<?php

namespace App\Support\Workspace\Rbac;

enum WorkspaceRbacLegacyPreflightFailureReason: string
{
    case ZeroWorkspaces = 'zero_workspaces';
    case MultipleWorkspaces = 'multiple_workspaces';
    case ZeroDefaultWorkspaces = 'zero_default_workspaces';
    case MultipleDefaultWorkspaces = 'multiple_default_workspaces';
    case NoActiveStaffAdminOrDirector = 'no_active_staff_admin_or_director';
    case OnlyInactiveStaffAdminOrDirector = 'only_inactive_staff_admin_or_director';
    case SpatieRolesNonEmpty = 'spatie_roles_non_empty';
    case SpatieModelHasRolesNonEmpty = 'spatie_model_has_roles_non_empty';
    case SpatieModelHasPermissionsNonEmpty = 'spatie_model_has_permissions_non_empty';
    case SpatieRoleHasPermissionsNonEmpty = 'spatie_role_has_permissions_non_empty';
    case MissingCanonicalPermissionCodes = 'missing_canonical_permission_codes';
}
