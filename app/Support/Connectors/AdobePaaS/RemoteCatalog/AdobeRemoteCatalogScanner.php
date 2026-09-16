<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

use App\Enums\SyncDataDomain;
use App\Models\ConnectorAccount;
use App\Models\RemoteCatalogSnapshot;
use App\Services\Connectors\RemoteCatalogScanService;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshot;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use App\Support\Connectors\RemoteCatalog\RemoteCatalogItemCandidate;
use Throwable;

final class AdobeRemoteCatalogScanner
{
    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeConnectorAccountTargetSnapshotResolver $targetResolver,
        private readonly AdobeRemoteCatalogEnumerator $enumerator,
        private readonly RemoteCatalogScanService $scanService,
    ) {}

    public function scan(ConnectorAccount $account): RemoteCatalogSnapshot
    {
        $account = $this->freshAccount($account);
        $context = $this->contextFactory->create((string) $account->workspace_id, (string) $account->id);
        $target = $this->targetResolver->resolve($account);
        $boundary = $this->enumerator->captureBoundary($context);
        $scan = $this->scanService->begin(
            $account,
            SyncDataDomain::Products,
            $target->toEnvelopeArray(),
            $boundary->totalCount,
        );

        try {
            $received = $this->enumerator->enumerateWithinBoundary(
                $context,
                $boundary,
                function (array $items) use ($scan): void {
                    $this->scanService->append(
                        $scan,
                        array_map(
                            static fn (AdobeRemoteCatalogItem $item): RemoteCatalogItemCandidate => new RemoteCatalogItemCandidate(
                                remoteIdentifier: (string) $item->entityId,
                                sku: $item->sku,
                                name: $item->name,
                                remoteType: $item->typeId,
                                remoteStatus: $item->status,
                                remoteUpdatedAt: $item->updatedAt,
                            ),
                            $items,
                        ),
                    );
                },
            );

            if ($received !== $boundary->totalCount) {
                throw new AdobeRemoteCatalogReadException('Adobe remote catalogue received count does not match the captured boundary.');
            }

            $this->assertTargetUnchanged($account, $target);

            return $this->scanService->publish($scan);
        } catch (Throwable $exception) {
            $failureCode = $exception instanceof AdobeRemoteCatalogTargetChangedException
                ? 'target_changed_during_scan'
                : 'remote_catalog_enumeration_failed';

            $this->scanService->fail($scan, $failureCode, $exception->getMessage());

            throw $exception;
        }
    }

    private function freshAccount(ConnectorAccount $account): ConnectorAccount
    {
        return ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->whereKey($account->id)
            ->firstOrFail();
    }

    private function assertTargetUnchanged(
        ConnectorAccount $account,
        AdobeConnectorAccountTargetSnapshot $capturedTarget,
    ): void {
        $fresh = $this->freshAccount($account);
        $currentTarget = $this->targetResolver->resolve($fresh);

        if (! $capturedTarget->equals($currentTarget)) {
            throw new AdobeRemoteCatalogTargetChangedException(
                'Adobe remote catalogue target changed while the scan was running.',
            );
        }
    }
}
