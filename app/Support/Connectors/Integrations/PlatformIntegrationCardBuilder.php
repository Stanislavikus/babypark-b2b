<?php

namespace App\Support\Connectors\Integrations;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Filament\Pages\Integrations\ConnectPlatformIntegration;
use App\Filament\Pages\Integrations\ListPlatformConnections;
use App\Filament\Resources\ConnectorAccountResource;
use App\Models\ConnectorAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Connectors\ConnectorAccountCapabilityPresentation;
use App\Support\Connectors\ConnectorAccountUiState;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\ConnectorUiFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class PlatformIntegrationCardBuilder
{
    public function __construct(
        private readonly EligibleConnectorPlatformCatalog $catalog,
        private readonly PlatformConnectionHealthRollup $rollup,
        private readonly IntegrationsStatusVocabulary $vocabulary,
        private readonly ConnectorAccountUiState $uiState,
        private readonly ConnectorProfileRegistry $profileRegistry,
    ) {}

    /**
     * @return Collection<int, PlatformIntegrationCard>
     */
    public function cardsFor(User $actor, Workspace $workspace): Collection
    {
        $platforms = $this->catalog->forWorkspace($actor, $workspace);
        $canManage = app(ConnectorAccountCapabilityPresentation::class)->canManage($actor, $workspace);

        $accountsQuery = ConnectorAccount::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('connector_definition_id', $platforms->map->id->all())
            ->orderBy('name');

        if ($canManage) {
            $accountsQuery->with([
                'connectionChecks' => fn ($query) => $query
                    ->select(['id', 'connector_account_id', 'status'])
                    ->whereIn('status', [
                        ConnectorConnectionCheckStatus::Queued,
                        ConnectorConnectionCheckStatus::Running,
                    ]),
            ]);
        }

        $accountsByDefinition = $accountsQuery
            ->get()
            ->groupBy('connector_definition_id');

        $canCreate = Gate::forUser($actor)->allows('create', [ConnectorAccount::class, $workspace]);

        return $platforms->map(function (EligibleConnectorPlatform $platform) use (
            $accountsByDefinition,
            $canCreate,
            $canManage,
        ): PlatformIntegrationCard {
            /** @var Collection<int, ConnectorAccount> $accounts */
            $accounts = $accountsByDefinition->get($platform->id, Collection::make())->values();
            $health = $this->rollup->rollup($accounts);
            $setupAvailable = $platform->allowsNewConnections()
                && $this->profileRegistry->resolveAccountSetupProfile($platform->code) !== null;

            return $this->buildCard(
                platform: $platform,
                health: $health,
                accounts: $accounts->all(),
                canCreate: $canCreate,
                setupAvailable: $setupAvailable,
                canManage: $canManage,
            );
        });
    }

    /**
     * @param  list<ConnectorAccount>  $accounts
     */
    private function buildCard(
        EligibleConnectorPlatform $platform,
        PlatformConnectionHealth $health,
        array $accounts,
        bool $canCreate,
        bool $setupAvailable,
        bool $canManage,
    ): PlatformIntegrationCard {
        if ($health->isNotConnected()) {
            return $this->notConnectedCard($platform, $health, $canCreate, $setupAvailable);
        }

        $singleAccount = $health->isSingleAccount() ? $accounts[0] : null;
        $runtimeOverlayLabel = null;

        if ($singleAccount !== null && $canManage) {
            // §3 — reuse UiState for active-check overlay only. Page-specific
            // IntegrationsStatusVocabulary owns stable landing labels/colors.
            $activeCheck = $this->uiState->activeConnectionCheck($singleAccount);
            $runtimeOverlayLabel = $this->uiState->runtimeStatusLabel($activeCheck);
        }

        $status = $health->connectionStatus;
        $this->vocabulary->assertRealStatus($status);

        $statusLabel = $status !== null
            ? $this->vocabulary->labelFor($status)
            : $this->vocabulary->notConnectedLabel();

        $secondaryLine = $health->isSingleAccount()
            ? $this->singleAccountSecondaryLine($singleAccount, $runtimeOverlayLabel)
            : $this->multiAccountSecondaryLine($health);

        [$action, $url, $label, $hint] = $this->resolvePrimaryAction(
            platform: $platform,
            health: $health,
            singleAccount: $singleAccount,
            canCreate: $canCreate,
            setupAvailable: $setupAvailable,
        );

        return new PlatformIntegrationCard(
            platform: $platform,
            health: $health,
            statusLabel: $statusLabel,
            statusColor: $this->vocabulary->colorFor($status),
            secondaryLine: $secondaryLine,
            runtimeOverlayLabel: $runtimeOverlayLabel,
            primaryAction: $action,
            primaryActionUrl: $url,
            primaryActionLabel: $label,
            secondaryActionHint: $hint,
            accounts: $accounts,
            singleAccount: $singleAccount,
            canCreate: $canCreate,
            setupAvailable: $setupAvailable,
        );
    }

    private function notConnectedCard(
        EligibleConnectorPlatform $platform,
        PlatformConnectionHealth $health,
        bool $canCreate,
        bool $setupAvailable,
    ): PlatformIntegrationCard {
        [$action, $url, $label, $hint] = $this->resolvePrimaryAction(
            platform: $platform,
            health: $health,
            singleAccount: null,
            canCreate: $canCreate,
            setupAvailable: $setupAvailable,
        );

        return new PlatformIntegrationCard(
            platform: $platform,
            health: $health,
            statusLabel: $this->vocabulary->notConnectedLabel(),
            statusColor: $this->vocabulary->colorFor(null),
            secondaryLine: '',
            runtimeOverlayLabel: null,
            primaryAction: $action,
            primaryActionUrl: $url,
            primaryActionLabel: $label,
            secondaryActionHint: $hint,
            accounts: [],
            singleAccount: null,
            canCreate: $canCreate,
            setupAvailable: $setupAvailable,
        );
    }

    private function singleAccountSecondaryLine(
        ?ConnectorAccount $account,
        ?string $runtimeOverlayLabel,
    ): string {
        if ($account === null) {
            return '';
        }

        if ($runtimeOverlayLabel !== null) {
            return $runtimeOverlayLabel;
        }

        if ($account->connection_status === ConnectorAccountConnectionStatus::Untested) {
            return __('connectors.ui.integrations.secondary.never_checked');
        }

        if ($account->last_checked_at !== null) {
            return __('connectors.ui.integrations.secondary.last_checked', [
                'when' => $this->formatRelativeCheckTime($account->last_checked_at),
            ]);
        }

        if ($account->connection_status === ConnectorAccountConnectionStatus::Connected) {
            // Connected without a timestamp is still a historical success fact — no freshness gate.
            return __('connectors.ui.integrations.secondary.last_check_unknown');
        }

        return '';
    }

    private function multiAccountSecondaryLine(PlatformConnectionHealth $health): string
    {
        $countLabel = trans_choice(
            'connectors.ui.integrations.secondary.connection_count',
            $health->accountCount,
            ['count' => $health->accountCount],
        );

        if ($health->connectionStatus === ConnectorAccountConnectionStatus::Disabled) {
            $disabledDetail = $health->disabledCount === 2
                ? __('connectors.ui.integrations.secondary.both_disabled')
                : __('connectors.ui.integrations.secondary.all_disabled');

            return $countLabel.' · '.$disabledDetail;
        }

        $parts = [$countLabel];

        if ($health->attentionRequiredCount > 0) {
            $parts[] = trans_choice(
                'connectors.ui.integrations.secondary.attention_count',
                $health->attentionRequiredCount,
                ['count' => $health->attentionRequiredCount],
            );
        } elseif ($health->temporarilyUnavailableCount > 0) {
            $parts[] = trans_choice(
                'connectors.ui.integrations.secondary.temporary_count',
                $health->temporarilyUnavailableCount,
                ['count' => $health->temporarilyUnavailableCount],
            );
        } elseif ($health->untestedCount > 0) {
            $parts[] = trans_choice(
                'connectors.ui.integrations.secondary.untested_count',
                $health->untestedCount,
                ['count' => $health->untestedCount],
            );
        } elseif ($health->disabledCount > 0) {
            $parts = [
                trans_choice(
                    'connectors.ui.integrations.secondary.active_count',
                    $health->enabledCount,
                    ['count' => $health->enabledCount],
                ),
                trans_choice(
                    'connectors.ui.integrations.secondary.disabled_count',
                    $health->disabledCount,
                    ['count' => $health->disabledCount],
                ),
            ];
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string, 3: ?string}
     */
    private function resolvePrimaryAction(
        EligibleConnectorPlatform $platform,
        PlatformConnectionHealth $health,
        ?ConnectorAccount $singleAccount,
        bool $canCreate,
        bool $setupAvailable,
    ): array {
        if ($health->isNotConnected()) {
            if (! $canCreate) {
                return [
                    PlatformIntegrationCard::ACTION_NONE,
                    null,
                    null,
                    __('connectors.ui.integrations.actions.ask_admin'),
                ];
            }

            // Catalog eligibility already requires AccountSetup for 0-account
            // Active platforms. Do not invent Coming Soon UX here.
            if (! $setupAvailable) {
                return [
                    PlatformIntegrationCard::ACTION_NONE,
                    null,
                    null,
                    null,
                ];
            }

            return [
                PlatformIntegrationCard::ACTION_CONNECT,
                ConnectPlatformIntegration::getUrl(['platform' => $platform->code]),
                __('connectors.ui.integrations.actions.connect'),
                null,
            ];
        }

        if ($health->isSingleAccount() && $singleAccount !== null) {
            return [
                PlatformIntegrationCard::ACTION_OPEN,
                ConnectorAccountResource::getUrl('view', ['record' => $singleAccount]),
                __('connectors.ui.integrations.actions.open'),
                null,
            ];
        }

        return [
            PlatformIntegrationCard::ACTION_OPEN,
            ListPlatformConnections::getUrl(['platform' => $platform->code]),
            __('connectors.ui.integrations.actions.open'),
            null,
        ];
    }

    private function formatRelativeCheckTime(mixed $value): string
    {
        $date = $value instanceof Carbon
            ? $value->copy()->locale(app()->getLocale())
            : Carbon::parse($value)->locale(app()->getLocale());

        if ($date->isToday()) {
            return __('connectors.ui.integrations.time.today', [
                'time' => $date->isoFormat('HH:mm'),
            ]);
        }

        if ($date->isYesterday()) {
            return __('connectors.ui.integrations.time.yesterday', [
                'time' => $date->isoFormat('HH:mm'),
            ]);
        }

        return ConnectorUiFormatter::formatDateTime($date) ?? $date->toDateTimeString();
    }
}
