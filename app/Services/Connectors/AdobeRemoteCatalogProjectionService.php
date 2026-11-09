<?php

namespace App\Services\Connectors;

use App\Enums\RemoteCatalogScanStatus;
use App\Enums\SyncDataDomain;
use App\Models\AdobeProductAttributeSet;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\RemoteCatalogScan;
use App\Models\RemoteCatalogSnapshot;
use App\Models\RemoteCatalogSnapshotItem;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogSummary;
use App\Support\Workspace\WorkspaceScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
            ->whereIn('remote_identifier', $this->trustedRemoteIdentifiers($account))
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

        $trustedLinkIds = $this->trustedLinks($account)->pluck('id')->all();

        return $query
            ->select('remote_catalog_snapshot_items.*')
            ->where('snapshot_id', $snapshot->id)
            ->with(['categories' => static fn ($categories) => $categories
                ->withoutGlobalScope(WorkspaceScope::class)
                ->orderByRaw('position is null, position')
                ->orderBy('external_category_id')])
            ->addSelect([
                'attribute_set_name' => AdobeProductAttributeSet::withoutWorkspaceScope()
                    ->select('name')
                    ->where('workspace_id', $account->workspace_id)
                    ->where('connector_account_id', $account->id)
                    ->whereNull('missing_since')
                    ->whereColumn(
                        'provider_attribute_set_id',
                        'remote_catalog_snapshot_items.external_attribute_set_id',
                    )
                    ->limit(1),
                'is_linked' => ExternalRecordLink::withoutWorkspaceScope()
                    ->selectRaw('COUNT(*) > 0')
                    ->where('workspace_id', $account->workspace_id)
                    ->where('connector_account_id', $account->id)
                    ->whereIn('id', $trustedLinkIds)
                    ->whereColumn(
                        'external_record_discriminator',
                        'remote_catalog_snapshot_items.remote_identifier',
                    )
                    ->limit(1),
                'linked_product_id' => ExternalRecordLink::withoutWorkspaceScope()
                    ->select('product_id')
                    ->where('workspace_id', $account->workspace_id)
                    ->where('connector_account_id', $account->id)
                    ->whereIn('id', $trustedLinkIds)
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
            'linked' => $query->whereIn('remote_identifier', $this->trustedRemoteIdentifiers($account)),
            'unlinked' => $query->whereNotIn('remote_identifier', $this->trustedRemoteIdentifiers($account)),
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

    /** @return list<string> */
    private function trustedRemoteIdentifiers(ConnectorAccount $account): array
    {
        return $this->trustedLinks($account)
            ->pluck('external_record_discriminator')
            ->filter(static fn ($value): bool => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** @return Collection<int, ExternalRecordLink> */
    private function trustedLinks(ConnectorAccount $account): Collection
    {
        return ExternalRecordLink::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->get()
            ->filter(static fn (ExternalRecordLink $link): bool => $link->hasTrustedIdentity())
            ->values();
    }
}
