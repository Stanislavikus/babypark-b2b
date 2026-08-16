<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorCapability;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorProfileAvailability;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSource;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceResolutionException;
use App\Support\Connectors\Exceptions\ConnectorProfileNotFoundException;

final class ConnectorAccountUiState
{
    public function __construct(
        private readonly ConnectorProfileRegistry $profileRegistry,
        private readonly ConnectorSafeMessagePresenter $safeMessagePresenter,
        private readonly ConnectorDiscoverySourceResolver $discoverySourceResolver,
    ) {}

    public function activeConnectionCheck(ConnectorAccount $account): ?ConnectorConnectionCheck
    {
        if (! $account->relationLoaded('connectionChecks')) {
            return $account->connectionChecks()
                ->whereIn('status', [
                    ConnectorConnectionCheckStatus::Queued,
                    ConnectorConnectionCheckStatus::Running,
                ])
                ->first();
        }

        return $account->connectionChecks
            ->first(fn (ConnectorConnectionCheck $check): bool => in_array($check->status, [
                ConnectorConnectionCheckStatus::Queued,
                ConnectorConnectionCheckStatus::Running,
            ], true));
    }

    public function hasActiveConnectionCheck(ConnectorAccount $account): bool
    {
        return $this->activeConnectionCheck($account) !== null;
    }

    public function runtimeStatusLabel(?ConnectorConnectionCheck $activeCheck): ?string
    {
        if ($activeCheck === null) {
            return null;
        }

        return match ($activeCheck->status) {
            ConnectorConnectionCheckStatus::Queued => __('connectors.ui.runtime.waiting'),
            ConnectorConnectionCheckStatus::Running => __('connectors.ui.runtime.running'),
            default => null,
        };
    }

    public function runtimeStatusColor(?ConnectorConnectionCheck $activeCheck): ?string
    {
        if ($activeCheck === null) {
            return null;
        }

        return match ($activeCheck->status) {
            ConnectorConnectionCheckStatus::Queued,
            ConnectorConnectionCheckStatus::Running => 'info',
            default => null,
        };
    }

    public function stableStatusLabel(ConnectorAccountConnectionStatus $status): string
    {
        return __($status->label());
    }

    public function stableStatusColor(ConnectorAccountConnectionStatus $status): string
    {
        return match ($status) {
            ConnectorAccountConnectionStatus::Connected => 'success',
            ConnectorAccountConnectionStatus::AttentionRequired => 'warning',
            ConnectorAccountConnectionStatus::TemporarilyUnavailable => 'danger',
            ConnectorAccountConnectionStatus::Untested,
            ConnectorAccountConnectionStatus::Disabled => 'gray',
        };
    }

    public function attentionMessage(ConnectorAccount $account): ?string
    {
        if (! in_array($account->connection_status, [
            ConnectorAccountConnectionStatus::AttentionRequired,
            ConnectorAccountConnectionStatus::TemporarilyUnavailable,
        ], true)) {
            return null;
        }

        return $this->safeMessagePresenter->present($account->last_error_message_key);
    }

    public function storeContextLabel(ConnectorAccount $account): ?string
    {
        $parts = array_values(array_filter([
            $account->store_code,
            $account->tenant_context,
        ], fn (?string $value): bool => filled($value)));

        if ($parts === []) {
            return null;
        }

        return implode(' / ', $parts);
    }

    public function profileSupportsManualCheck(ConnectorAccount $account): bool
    {
        return $this->profileAvailabilityState($account) === ConnectorProfileAvailability::Available;
    }

    public function profileAvailabilityState(ConnectorAccount $account): ConnectorProfileAvailability
    {
        $profileCode = $this->resolveAuthProfileCode($account);

        try {
            $definition = $this->profileRegistry->profileDefinition($profileCode);
        } catch (ConnectorProfileNotFoundException) {
            return ConnectorProfileAvailability::ProfileNotFound;
        }

        if (! $definition->enabled) {
            return ConnectorProfileAvailability::ProfileDisabled;
        }

        if (! $definition->supports(ConnectorCapability::ConnectionCheck)) {
            return ConnectorProfileAvailability::CapabilityUnsupported;
        }

        return ConnectorProfileAvailability::Available;
    }

    /**
     * @return array{enabled: bool, label: string, disabled_reason: ?string}
     */
    public function manualCheckActionState(ConnectorAccount $account): array
    {
        if (! $account->is_enabled) {
            return [
                'enabled' => false,
                'label' => __('connectors.ui.actions.run_connection_check'),
                'disabled_reason' => __('connectors.ui.disabled_reasons.account_disabled'),
            ];
        }

        $profileAvailability = $this->profileAvailabilityState($account);

        if ($profileAvailability !== ConnectorProfileAvailability::Available) {
            $reasonKey = $profileAvailability->disabledReasonKey();

            return [
                'enabled' => false,
                'label' => __('connectors.ui.actions.run_connection_check'),
                'disabled_reason' => $reasonKey !== null ? __($reasonKey) : null,
            ];
        }

        if ($this->hasActiveConnectionCheck($account)) {
            return [
                'enabled' => false,
                'label' => __('connectors.ui.actions.check_already_active'),
                'disabled_reason' => __('connectors.ui.disabled_reasons.check_already_active'),
            ];
        }

        return [
            'enabled' => true,
            'label' => __('connectors.ui.actions.run_connection_check'),
            'disabled_reason' => null,
        ];
    }

    public function formatDuration(?int $durationMs): ?string
    {
        return ConnectorUiFormatter::formatDuration($durationMs);
    }

    public function initiatorLabel(?ConnectorConnectionCheck $check): string
    {
        return $this->initiatorLabelForUserRelation($check);
    }

    public function discoveryInitiatorLabel(?ConnectorDiscoveryRun $run): string
    {
        return $this->initiatorLabelForUserRelation($run);
    }

    public function activeDiscoveryRun(ConnectorAccount $account): ?ConnectorDiscoveryRun
    {
        if (! $account->relationLoaded('discoveryRuns')) {
            return $account->discoveryRuns()
                ->whereIn('status', [
                    ConnectorDiscoveryRunStatus::Queued,
                    ConnectorDiscoveryRunStatus::Running,
                ])
                ->first();
        }

        return $account->discoveryRuns
            ->first(fn (ConnectorDiscoveryRun $run): bool => in_array($run->status, [
                ConnectorDiscoveryRunStatus::Queued,
                ConnectorDiscoveryRunStatus::Running,
            ], true));
    }

    public function hasActiveDiscoveryRun(ConnectorAccount $account): bool
    {
        return $this->activeDiscoveryRun($account) !== null;
    }

    public function discoveryRuntimeStatusLabel(?ConnectorDiscoveryRun $activeRun): ?string
    {
        if ($activeRun === null) {
            return null;
        }

        return match ($activeRun->status) {
            ConnectorDiscoveryRunStatus::Queued => __('connectors.ui.runtime.discovery_waiting'),
            ConnectorDiscoveryRunStatus::Running => __('connectors.ui.runtime.discovery_running'),
            default => null,
        };
    }

    public function discoveryRuntimeStatusColor(?ConnectorDiscoveryRun $activeRun): ?string
    {
        if ($activeRun === null) {
            return null;
        }

        return match ($activeRun->status) {
            ConnectorDiscoveryRunStatus::Queued,
            ConnectorDiscoveryRunStatus::Running => 'info',
            default => null,
        };
    }

    public function discoveryStatusLabel(ConnectorDiscoveryRunStatus $status): string
    {
        return __($status->label());
    }

    public function discoveryStatusColor(ConnectorDiscoveryRunStatus $status): string
    {
        return match ($status) {
            ConnectorDiscoveryRunStatus::Succeeded => 'success',
            ConnectorDiscoveryRunStatus::Failed => 'danger',
            ConnectorDiscoveryRunStatus::Cancelled => 'gray',
            ConnectorDiscoveryRunStatus::Queued,
            ConnectorDiscoveryRunStatus::Running => 'info',
        };
    }

    public function discoveryErrorMessage(?ConnectorDiscoveryRun $run): ?string
    {
        if ($run === null || $run->status !== ConnectorDiscoveryRunStatus::Failed) {
            return null;
        }

        return $this->safeMessagePresenter->present($run->user_message_key);
    }

    public function profileSchemaDiscoveryAvailabilityState(ConnectorAccount $account): ConnectorProfileAvailability
    {
        $profileCode = $this->resolveAuthProfileCode($account);

        try {
            $definition = $this->profileRegistry->profileDefinition($profileCode);
        } catch (ConnectorProfileNotFoundException) {
            return ConnectorProfileAvailability::ProfileNotFound;
        }

        if (! $definition->enabled) {
            return ConnectorProfileAvailability::ProfileDisabled;
        }

        if (! $definition->supports(ConnectorCapability::SchemaDiscovery)) {
            return ConnectorProfileAvailability::CapabilityUnsupported;
        }

        return ConnectorProfileAvailability::Available;
    }

    public function isDiscoverySourceAvailable(ConnectorAccount $account): bool
    {
        try {
            $this->discoverySourceResolver->resolve($account);

            return true;
        } catch (ConnectorDiscoverySourceResolutionException) {
            return false;
        }
    }

    /**
     * @return array{enabled: bool, label: string, disabled_reason: ?string}
     */
    public function manualDiscoveryActionState(ConnectorAccount $account): array
    {
        $label = __('connectors.ui.actions.refresh_available_fields');

        if (! $account->is_enabled) {
            return [
                'enabled' => false,
                'label' => $label,
                'disabled_reason' => __('connectors.ui.disabled_reasons.account_disabled'),
            ];
        }

        $profileAvailability = $this->profileSchemaDiscoveryAvailabilityState($account);

        if ($profileAvailability !== ConnectorProfileAvailability::Available) {
            $reasonKey = match ($profileAvailability) {
                ConnectorProfileAvailability::CapabilityUnsupported => 'connectors.ui.disabled_reasons.discovery_capability_unsupported',
                default => $profileAvailability->disabledReasonKey(),
            };

            return [
                'enabled' => false,
                'label' => $label,
                'disabled_reason' => $reasonKey !== null ? __($reasonKey) : null,
            ];
        }

        if ($this->hasActiveDiscoveryRun($account)) {
            return [
                'enabled' => false,
                'label' => __('connectors.ui.actions.available_fields_refresh_active'),
                'disabled_reason' => __('connectors.ui.disabled_reasons.available_fields_refresh_active'),
            ];
        }

        if (! $this->isDiscoverySourceAvailable($account)) {
            return [
                'enabled' => false,
                'label' => $label,
                'disabled_reason' => __('connectors.ui.disabled_reasons.discovery_source_unavailable'),
            ];
        }

        return [
            'enabled' => true,
            'label' => $label,
            'disabled_reason' => null,
        ];
    }

    public function availableFieldsRefreshingLabel(?ConnectorDiscoveryRun $activeRun): ?string
    {
        if ($activeRun === null) {
            return null;
        }

        return match ($activeRun->status) {
            ConnectorDiscoveryRunStatus::Queued => __('connectors.ui.available_fields.refreshing'),
            ConnectorDiscoveryRunStatus::Running => __('connectors.ui.available_fields.refreshing'),
            default => null,
        };
    }

    public function availableFieldsCheckedAt(ConnectorAccount $account, ?ConnectorDiscoveryRun $latestRun): ?string
    {
        if ($account->last_successful_discovery_at !== null) {
            return ConnectorUiFormatter::formatDateTime($account->last_successful_discovery_at);
        }

        if ($latestRun?->status === ConnectorDiscoveryRunStatus::Succeeded && $latestRun->finished_at !== null) {
            return ConnectorUiFormatter::formatDateTime($latestRun->finished_at);
        }

        return null;
    }

    public function availableFieldsFieldCount(
        ConnectorAccount $account,
        ?ConnectorDiscoveryRun $latestRun,
        ?ConnectorDiscoveryRun $latestSuccessfulRun = null,
    ): ?int {
        if ($latestRun?->status === ConnectorDiscoveryRunStatus::Succeeded
            && $latestRun->relationLoaded('snapshot')
            && $latestRun->snapshot !== null) {
            return $latestRun->snapshot->field_count;
        }

        if ($latestSuccessfulRun?->relationLoaded('snapshot')
            && $latestSuccessfulRun->snapshot !== null) {
            return $latestSuccessfulRun->snapshot->field_count;
        }

        return null;
    }

    public function availableFieldsFailureMessage(?ConnectorDiscoveryRun $latestRun): ?string
    {
        if ($latestRun === null || $latestRun->status !== ConnectorDiscoveryRunStatus::Failed) {
            return null;
        }

        return $this->discoveryErrorMessage($latestRun);
    }

    public function schemaSourceLabel(?ConnectorSchemaSource $source): string
    {
        if ($source === null || ! filled($source->label)) {
            return __('connectors.ui.common.dash');
        }

        return $source->label;
    }

    public function snapshotStateLabel(ConnectorSchemaSnapshot $snapshot): ?string
    {
        if ($snapshot->previous_snapshot_id === null) {
            return __('connectors.ui.snapshot.first_snapshot');
        }

        $previous = $snapshot->relationLoaded('previousSnapshot')
            ? $snapshot->previousSnapshot
            : $snapshot->previousSnapshot()->first(['id', 'canonical_hash']);

        if ($previous === null) {
            return null;
        }

        if ($snapshot->canonical_hash === $previous->canonical_hash) {
            return __('connectors.ui.snapshot.no_change');
        }

        return null;
    }

    private function initiatorLabelForUserRelation(?object $record): string
    {
        if ($record === null) {
            return __('connectors.ui.initiator.system');
        }

        $user = $record->relationLoaded('initiatedByUser')
            ? $record->initiatedByUser
            : $record->initiatedByUser()->first();

        if ($user === null) {
            return __('connectors.ui.initiator.system');
        }

        if (filled($user->name)) {
            return $user->name;
        }

        if (filled($user->email)) {
            return $user->email;
        }

        return __('connectors.ui.initiator.system');
    }

    private function resolveAuthProfileCode(ConnectorAccount $account): string
    {
        if (filled($account->auth_profile)) {
            return $account->auth_profile;
        }

        $profileCode = ConnectorAccount::query()
            ->whereKey($account->getKey())
            ->value('auth_profile');

        return filled($profileCode) ? $profileCode : '';
    }
}
