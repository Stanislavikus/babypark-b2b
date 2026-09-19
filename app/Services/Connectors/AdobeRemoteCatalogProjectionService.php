<?php

namespace App\Services\Connectors;

use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Enums\RemoteCatalogScanStatus;
use App\Enums\SyncDataDomain;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\RemoteCatalogScan;
use App\Models\RemoteCatalogSnapshot;
use App\Models\RemoteCatalogSnapshotItem;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogSummary;
use Illuminate\Database\Eloquent\Builder;

final class AdobeRemoteCatalogProjectionService
{
    public function __construct(
        private readonly RemoteCatalogCurrentSnapshotResolver $currentSnapshotResolver,
        private readonly AdobeConnectorAccountTargetSnapshotResolver $targetResolver,
    ) {}

    public function summary(ConnectorAccount $account): AdobeRemoteCatalogSummary
    {
        $snapshot = $this->currentSnapshot($account);
        $scanRunning = RemoteCatalogScan::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('data_domain', SyncDataDomain::Products->value)
            ->where('status', RemoteCatalogScanStatus::Running->value)
            ->exists();

        if (! $snapshot instanceof RemoteCatalogSnapshot) {
            return new AdobeRemoteCatalogSummary(null, 0, 0, 0, $scanRunning);
        }

        $linkedCount = RemoteCatalogSnapshotItem::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('snapshot_id', $snapshot->id)
            ->whereIn('remote_identifier', $this->trustedRemoteIdentifiersQuery($account))
            ->count();

        return new AdobeRemoteCatalogSummary(
            snapshot: $snapshot,
            totalCount: $snapshot->item_count,
            linkedCount: $linkedCount,
            remoteOnlyCount: max(0, $snapshot->item_count - $linkedCount),
            scanRunning: $scanRunning,
        );
    }

    /** @return Builder<RemoteCatalogSnapshotItem> */
    public function itemsQuery(ConnectorAccount $account, ?RemoteCatalogSnapshot $snapshot = null): Builder
    {
        $snapshot ??= $this->currentSnapshot($account);
        $query = RemoteCatalogSnapshotItem::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id);

        if (! $snapshot instanceof RemoteCatalogSnapshot) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->select('remote_catalog_snapshot_items.*')
            ->where('snapshot_id', $snapshot->id)
            ->addSelect([
                'is_linked' => ExternalRecordLink::withoutWorkspaceScope()
                    ->selectRaw('1')
                    ->where('workspace_id', $account->workspace_id)
                    ->where('connector_account_id', $account->id)
                    ->where('trust_origin', ExternalRecordLinkTrustOrigin::MerchantConfirmed->value)
                    ->whereNotNull('external_record_discriminator')
                    ->whereNotNull('established_by_workspace_user_id')
                    ->whereNotNull('established_at')
                    ->whereColumn(
                        'external_record_discriminator',
                        'remote_catalog_snapshot_items.remote_identifier',
                    )
                    ->limit(1),
            ]);
    }

    /** @return Builder<RemoteCatalogSnapshotItem> */
    public function remoteOnlyItemsQuery(ConnectorAccount $account, ?RemoteCatalogSnapshot $snapshot = null): Builder
    {
        return $this->filterItemsByLinkStatus(
            $this->itemsQuery($account, $snapshot),
            $account,
            'unlinked',
        );
    }

    /** @param Builder<RemoteCatalogSnapshotItem> $query */
    public function filterItemsByLinkStatus(Builder $query, ConnectorAccount $account, ?string $status): Builder
    {
        return match ($status) {
            'linked' => $query->whereIn('remote_identifier', $this->trustedRemoteIdentifiersQuery($account)),
            'unlinked' => $query->whereNotIn('remote_identifier', $this->trustedRemoteIdentifiersQuery($account)),
            default => $query,
        };
    }

    public function currentSnapshot(ConnectorAccount $account): ?RemoteCatalogSnapshot
    {
        return $this->currentSnapshotResolver->resolve(
            $account,
            SyncDataDomain::Products,
            $this->targetResolver->resolve($account)->toEnvelopeArray(),
        );
    }

    /** @return Builder<ExternalRecordLink> */
    private function trustedRemoteIdentifiersQuery(ConnectorAccount $account): Builder
    {
        return ExternalRecordLink::withoutWorkspaceScope()
            ->select('external_record_discriminator')
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('trust_origin', ExternalRecordLinkTrustOrigin::MerchantConfirmed->value)
            ->whereNotNull('external_record_discriminator')
            ->whereNotNull('established_by_workspace_user_id')
            ->whereNotNull('established_at');
    }
}
