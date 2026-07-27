<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorCapability;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorProfileAvailability;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Support\Connectors\Exceptions\ConnectorProfileNotFoundException;

final class ConnectorAccountUiState
{
    public function __construct(
        private readonly ConnectorProfileRegistry $profileRegistry,
        private readonly ConnectorSafeMessagePresenter $safeMessagePresenter,
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
        try {
            $definition = $this->profileRegistry->profileDefinition($account->auth_profile);
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
        if ($check === null) {
            return __('connectors.ui.initiator.system');
        }

        $user = $check->relationLoaded('initiatedByUser')
            ? $check->initiatedByUser
            : $check->initiatedByUser()->first();

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
}
