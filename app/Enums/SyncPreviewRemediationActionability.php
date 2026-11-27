<?php

namespace App\Enums;

enum SyncPreviewRemediationActionability: string
{
    case ActionAvailable = 'action_available';
    case ViewOnly = 'view_only';
    case PermissionRequired = 'permission_required';
    case NoEditSurface = 'no_edit_surface';
    case CurrentConfigurationChanged = 'current_configuration_changed';
}
