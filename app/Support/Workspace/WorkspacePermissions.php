<?php

namespace App\Support\Workspace;

final class WorkspacePermissions
{
    public const VIEW_CONNECTOR_ACCOUNTS = 'view_connector_accounts';

    public const RUN_CONNECTOR_DISCOVERY = 'run_connector_discovery';

    public const MANAGE_CONNECTOR_ACCOUNTS = 'manage_connector_accounts';

    public const VIEW_SYNC_MAPPINGS = 'view_sync_mappings';

    public const MANAGE_SYNC_MAPPINGS = 'manage_sync_mappings';

    public const MANAGE_WORKSPACE_ACCESS = 'manage_workspace_access';

    public const MANAGE_TAX_SETTINGS = 'manage_workspace_tax_settings';

    public const RUN_SYNC_PREVIEW = 'run_sync_preview';

    public const RUN_SYNC_LIVE = 'run_sync_live';

    public const MANAGE_SYNC_CONFIGURATIONS = 'manage_sync_configurations';

    /**
     * @return list<string>
     */
    public static function catalogue(): array
    {
        return [
            self::VIEW_CONNECTOR_ACCOUNTS,
            self::RUN_CONNECTOR_DISCOVERY,
            self::MANAGE_CONNECTOR_ACCOUNTS,
            self::VIEW_SYNC_MAPPINGS,
            self::MANAGE_SYNC_MAPPINGS,
            self::MANAGE_WORKSPACE_ACCESS,
            self::MANAGE_TAX_SETTINGS,
            self::RUN_SYNC_PREVIEW,
            self::RUN_SYNC_LIVE,
            self::MANAGE_SYNC_CONFIGURATIONS,
        ];
    }
}
