<?php

namespace App\Services\Sync\EntityTrust;

use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\RemoteCatalogSnapshotItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connectors\AdobeRemoteCatalogProjectionService;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshot;
use App\Support\Sync\EntityTrust\EntityTrustMerchantOutcome;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use Illuminate\Database\Eloquent\Builder;

final class AdobeRemoteCatalogEntityTrustService
{
    public function __construct(
        private readonly AdobeProductEntityTrustAuthorizationService $authorization,
        private readonly AdobeRemoteCatalogProjectionService $remoteCatalog,
        private readonly EntityTrustMerchantOrchestrator $orchestrator,
    ) {}

    /**
     * @return list<array{id: string, label: string}>
     */
    public function candidateProducts(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
        string $remoteCatalogItemId,
        ?string $search = null,
        int $limit = 25,
    ): array {
        $account = $this->authorization->resolveConnectorAccount($actor, $workspace, $connectorAccountId);
        $item = $this->resolveCurrentRemoteOnlyItem($account, $remoteCatalogItemId);
        $type = $this->remoteType($item);
        $sku = $this->remoteSku($item);

        $query = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true);

        if ($type === 'simple') {
            $query->whereHas('variants', static fn (Builder $variantQuery): Builder => $variantQuery
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->where('sku', $sku));
        } else {
            $term = trim((string) $search);

            if ($term !== '') {
                $query->where(function (Builder $candidateQuery) use ($term, $workspace): void {
                    $candidateQuery
                        ->where('name', 'like', '%'.$term.'%')
                        ->orWhere('sku', 'like', '%'.$term.'%')
                        ->orWhereHas('variants', static fn (Builder $variantQuery): Builder => $variantQuery
                            ->where('workspace_id', $workspace->id)
                            ->where('sku', 'like', '%'.$term.'%'));
                });
            }
        }

        return $query
            ->orderBy('name')
            ->orderBy('id')
            ->limit(max(1, min($limit, 50)))
            ->get(['id', 'name', 'sku'])
            ->map(static fn (Product $product): array => [
                'id' => (string) $product->id,
                'label' => trim((string) $product->name).' · '.((string) $product->sku !== '' ? $product->sku : '—'),
            ])
            ->values()
            ->all();
    }

    public function requestReview(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
        string $remoteCatalogItemId,
        string $productId,
    ): EntityTrustMerchantOutcome {
        $account = $this->authorization->resolveConnectorAccount($actor, $workspace, $connectorAccountId);
        $item = $this->resolveCurrentRemoteOnlyItem($account, $remoteCatalogItemId);
        $product = $this->resolveProduct($workspace, $productId);
        $type = $this->remoteType($item);
        $sku = $this->remoteSku($item);
        $logicalEntityId = $this->logicalEntityId($item);
        $expectedTargetSnapshot = $this->expectedTargetSnapshot($item);

        if ($type === 'simple') {
            $matchesSelectedProduct = $product->variants
                ->contains(static fn ($variant): bool => $variant->is_active && $variant->sku === $sku);

            if (! $matchesSelectedProduct) {
                throw EntityTrustException::candidateUntrusted();
            }
        }

        return $this->orchestrator->requestReview(
            $actor,
            $workspace,
            $account,
            $product,
            isConfigurableFamily: $type === 'configurable',
            explicitRelink: false,
            existingParentSkuHint: $type === 'configurable' ? $sku : null,
            expectedPrimaryLogicalEntityId: $logicalEntityId,
            expectedTargetSnapshot: $expectedTargetSnapshot,
        );
    }

    public function confirm(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
        string $productId,
        string $reviewFlowId,
    ): EntityTrustMerchantOutcome {
        $account = $this->authorization->resolveConnectorAccount($actor, $workspace, $connectorAccountId);
        $product = $this->resolveProduct($workspace, $productId);

        return $this->orchestrator->confirm(
            $actor,
            $workspace,
            $account,
            $product,
            $reviewFlowId,
        );
    }

    private function resolveCurrentRemoteOnlyItem(
        ConnectorAccount $account,
        string $remoteCatalogItemId,
    ): RemoteCatalogSnapshotItem {
        $snapshot = $this->remoteCatalog->currentSnapshot($account);

        if ($snapshot === null) {
            throw EntityTrustException::candidateNotFound();
        }

        $item = $this->remoteCatalog
            ->remoteOnlyItemsQuery($account, $snapshot)
            ->whereKey($remoteCatalogItemId)
            ->first();

        if (! $item instanceof RemoteCatalogSnapshotItem) {
            throw EntityTrustException::candidateNotFound();
        }

        $item->setRelation('snapshot', $snapshot);

        return $item;
    }

    private function expectedTargetSnapshot(RemoteCatalogSnapshotItem $item): AdobeConnectorAccountTargetSnapshot
    {
        $snapshot = $item->getRelation('snapshot');
        $target = $snapshot === null
            ? null
            : AdobeConnectorAccountTargetSnapshot::fromEnvelopeArray($snapshot->target_context ?? []);

        if (! $target instanceof AdobeConnectorAccountTargetSnapshot) {
            throw EntityTrustException::candidateUntrusted();
        }

        return $target;
    }

    private function resolveProduct(Workspace $workspace, string $productId): Product
    {
        $product = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->whereKey($productId)
            ->with('variants')
            ->first();

        if (! $product instanceof Product) {
            throw EntityTrustException::candidateNotFound();
        }

        return $product;
    }

    private function remoteType(RemoteCatalogSnapshotItem $item): string
    {
        $type = strtolower(trim((string) $item->remote_type));

        if (! in_array($type, ['simple', 'configurable'], true)) {
            throw EntityTrustException::remoteTypeMismatch();
        }

        return $type;
    }

    private function remoteSku(RemoteCatalogSnapshotItem $item): string
    {
        $sku = trim((string) $item->sku);

        if ($sku === '') {
            throw EntityTrustException::candidateUntrusted();
        }

        return $sku;
    }

    private function logicalEntityId(RemoteCatalogSnapshotItem $item): int
    {
        $identifier = trim($item->remote_identifier);

        if ($identifier === '' || ! ctype_digit($identifier)) {
            throw EntityTrustException::candidateUntrusted();
        }

        $id = (int) $identifier;

        if ($id <= 0) {
            throw EntityTrustException::candidateUntrusted();
        }

        return $id;
    }
}
