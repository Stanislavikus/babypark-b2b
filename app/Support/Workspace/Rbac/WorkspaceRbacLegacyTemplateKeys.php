<?php

namespace App\Support\Workspace\Rbac;

use App\Support\Workspace\WorkspacePermissions;

final class WorkspaceRbacLegacyTemplateKeys
{
    public const ACCESS_MANAGER = 'legacy_workspace_access_manager';

    public const CONNECTOR_DISCOVERY_OPERATOR = 'legacy_connector_discovery_operator';

    /**
     * @return list<string>
     */
    public static function bootstrapKeys(): array
    {
        return [
            self::ACCESS_MANAGER,
            self::CONNECTOR_DISCOVERY_OPERATOR,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function permissionBundles(): array
    {
        return [
            self::ACCESS_MANAGER => [
                WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
                WorkspacePermissions::RUN_CONNECTOR_DISCOVERY,
                WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS,
                WorkspacePermissions::MANAGE_WORKSPACE_ACCESS,
                WorkspacePermissions::MANAGE_TAX_SETTINGS,
            ],
            self::CONNECTOR_DISCOVERY_OPERATOR => [
                WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
                WorkspacePermissions::RUN_CONNECTOR_DISCOVERY,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function permissionsForKey(string $templateKey): array
    {
        return self::permissionBundles()[$templateKey] ?? [];
    }
}
