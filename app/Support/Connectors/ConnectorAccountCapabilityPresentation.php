<?php

namespace App\Support\Connectors;

use App\Models\ConnectorAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * Capability-based Connector account presentation tiers over Workspace RBAC.
 * Not job-title semantics — effective Workspace permissions only.
 */
final class ConnectorAccountCapabilityPresentation
{
    public function __construct(
        private readonly ConnectorAuthorization $connectorAuthorization,
    ) {}

    /**
     * @return list<string>
     */
    public static function safeSelectColumns(): array
    {
        return [
            'id',
            'workspace_id',
            'connector_definition_id',
            'name',
            'is_enabled',
            'connection_status',
            'last_checked_at',
            'last_successful_check_at',
            'last_discovery_at',
            'last_successful_discovery_at',
            'last_error_cause',
            'last_error_actionability',
            'last_error_message_key',
            'last_error_at',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    /**
     * @return list<string>
     */
    public static function hiddenAttributes(): array
    {
        return [
            'credentials',
            'settings',
            'base_url',
            'store_code',
            'tenant_context',
            'auth_profile',
        ];
    }

    public function canManage(User $user, Workspace $workspace): bool
    {
        return $this->connectorAuthorization->canManage($user, $workspace);
    }

    public function canDiscoveryControl(User $user, Workspace $workspace): bool
    {
        return $this->connectorAuthorization->canDiscoveryControl($user, $workspace);
    }

    public function isViewOnly(User $user, Workspace $workspace): bool
    {
        return $this->connectorAuthorization->canSafeRead($user, $workspace)
            && ! $this->connectorAuthorization->canDiscoveryControl($user, $workspace);
    }

    public function needsRestrictedProjection(User $user, Workspace $workspace): bool
    {
        return ! $this->canManage($user, $workspace);
    }

    public function showActiveConnectionCheck(User $user, Workspace $workspace): bool
    {
        return $this->canManage($user, $workspace);
    }

    public function showDiscoveryExecution(User $user, Workspace $workspace): bool
    {
        return $this->canDiscoveryControl($user, $workspace);
    }

    public function applyRestrictedQuery(Builder $query, User $user, Workspace $workspace): Builder
    {
        if (! $this->needsRestrictedProjection($user, $workspace)) {
            return $query;
        }

        return $query->select(self::safeSelectColumns());
    }

    public function sanitizeRecord(ConnectorAccount $record, User $user, Workspace $workspace): ConnectorAccount
    {
        if (! $this->needsRestrictedProjection($user, $workspace)) {
            return $record;
        }

        return $record->makeHidden(self::hiddenAttributes());
    }
}
