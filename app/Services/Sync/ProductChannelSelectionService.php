<?php

namespace App\Services\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;

final class ProductChannelSelectionService
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly SyncProductSelectionService $selectionService,
    ) {}

    public function canManage(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        );
    }

    /** @return array<string, string> */
    public function channelOptions(Workspace $workspace): array
    {
        return SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('data_domain', SyncDataDomain::Products)
            ->with(['connectorAccount.connectorDefinition'])
            ->orderBy('connector_account_id')
            ->get()
            ->filter(fn (SyncConfiguration $configuration): bool => $configuration
                ->enabledOperationSet()
                ->contains(SyncSemanticOperation::Export))
            ->mapWithKeys(fn (SyncConfiguration $configuration): array => [
                (string) $configuration->id => $this->channelLabel($configuration),
            ])
            ->all();
    }

    public function labelForConfiguration(Workspace $workspace, string $configurationId): ?string
    {
        $configuration = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('data_domain', SyncDataDomain::Products)
            ->with(['connectorAccount.connectorDefinition'])
            ->find($configurationId);

        return $configuration instanceof SyncConfiguration
            ? $this->channelLabel($configuration)
            : null;
    }

    /** @param iterable<int|string> $productIds */
    public function add(
        User $actor,
        Workspace $workspace,
        string $configurationId,
        iterable $productIds,
    ): SyncConfiguration {
        [$configuration, $account] = $this->resolveWritableContext($actor, $workspace, $configurationId);

        return $this->selectionService->add($account, (string) $configuration->id, $productIds);
    }

    /** @param iterable<int|string> $productIds */
    public function remove(
        User $actor,
        Workspace $workspace,
        string $configurationId,
        iterable $productIds,
    ): SyncConfiguration {
        [$configuration, $account] = $this->resolveWritableContext($actor, $workspace, $configurationId);

        return $this->selectionService->remove($account, (string) $configuration->id, $productIds);
    }

    /** @return array{0: SyncConfiguration, 1: ConnectorAccount} */
    private function resolveWritableContext(
        User $actor,
        Workspace $workspace,
        string $configurationId,
    ): array {
        if (! $this->canManage($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $configuration = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('data_domain', SyncDataDomain::Products)
            ->find($configurationId);

        if (! $configuration instanceof SyncConfiguration
            || ! $configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->find($configuration->connector_account_id);

        if (! $account instanceof ConnectorAccount) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return [$configuration, $account];
    }

    public function channelLabel(SyncConfiguration $configuration): string
    {
        $account = $configuration->connectorAccount;
        $definition = $account?->connectorDefinition;
        $platform = $definition?->code === 'adobe_commerce'
            ? __('connectors.ui.layer_a.magento_name')
            : ($definition?->name ?? __('product_channels.unknown_platform'));
        $accountName = $account?->name ?? __('product_channels.unknown_account');

        return $platform.' · '.$accountName;
    }
}
