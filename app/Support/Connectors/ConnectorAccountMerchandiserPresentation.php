<?php

namespace App\Support\Connectors;

use App\Enums\UserRole;
use App\Models\ConnectorAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ConnectorAccountMerchandiserPresentation
{
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

    public static function isMerchandiser(?User $user): bool
    {
        return $user !== null && $user->role === UserRole::Merchandiser;
    }

    public static function applySafeQuery(Builder $query, ?User $user): Builder
    {
        if (! self::isMerchandiser($user)) {
            return $query;
        }

        return $query->select(self::safeSelectColumns());
    }

    public static function sanitizeRecord(ConnectorAccount $record, ?User $user): ConnectorAccount
    {
        if (! self::isMerchandiser($user)) {
            return $record;
        }

        return $record->makeHidden(self::hiddenAttributes());
    }
}
