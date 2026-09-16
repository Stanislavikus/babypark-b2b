<?php

namespace App\Services\Connectors;

use App\Enums\RemoteCatalogScanStatus;
use App\Enums\SyncDataDomain;
use App\Models\ConnectorAccount;
use App\Models\RemoteCatalogCurrentSnapshot;
use App\Models\RemoteCatalogScan;
use App\Models\RemoteCatalogSnapshot;
use App\Models\RemoteCatalogSnapshotItem;
use App\Services\Connectors\Exceptions\RemoteCatalogScanIncompleteException;
use App\Services\Connectors\Exceptions\RemoteCatalogScanStateException;
use App\Services\Connectors\Exceptions\StaleRemoteCatalogScanException;
use App\Support\Connectors\RemoteCatalog\RemoteCatalogItemCandidate;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RemoteCatalogScanService
{
    /**
     * @param  array<string, mixed>  $targetContext
     */
    public function begin(
        ConnectorAccount $account,
        SyncDataDomain $dataDomain,
        array $targetContext,
        ?int $expectedItemCount = null,
        ?string $executionToken = null,
    ): RemoteCatalogScan {
        $targetContext = $this->normalizeTargetContext($targetContext);

        if ($expectedItemCount !== null && $expectedItemCount < 0) {
            throw new InvalidArgumentException('Expected remote catalogue item count must be non-negative.');
        }

        return DB::transaction(function () use ($account, $dataDomain, $targetContext, $expectedItemCount, $executionToken): RemoteCatalogScan {
            $lockedAccount = ConnectorAccount::withoutWorkspaceScope()
                ->where('workspace_id', $account->workspace_id)
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $generation = ((int) RemoteCatalogScan::withoutWorkspaceScope()
                ->where('workspace_id', $lockedAccount->workspace_id)
                ->where('connector_account_id', $lockedAccount->id)
                ->where('data_domain', $dataDomain->value)
                ->max('generation')) + 1;

            $scan = RemoteCatalogScan::withoutWorkspaceScope()->create([
                'workspace_id' => $lockedAccount->workspace_id,
                'connector_account_id' => $lockedAccount->id,
                'data_domain' => $dataDomain,
                'target_context' => $targetContext,
                'status' => RemoteCatalogScanStatus::Running,
                'generation' => $generation,
                'execution_token' => $executionToken,
                'expected_item_count' => $expectedItemCount,
                'received_item_count' => 0,
                'started_at' => now(),
            ]);

            RemoteCatalogSnapshot::withoutWorkspaceScope()->create([
                'workspace_id' => $lockedAccount->workspace_id,
                'connector_account_id' => $lockedAccount->id,
                'data_domain' => $dataDomain,
                'scan_id' => $scan->id,
                'target_context' => $targetContext,
                'item_count' => 0,
            ]);

            return RemoteCatalogScan::withoutWorkspaceScope()->findOrFail($scan->id);
        });
    }

    /**
     * @param  iterable<RemoteCatalogItemCandidate>  $items
     */
    public function append(RemoteCatalogScan $scan, iterable $items): void
    {
        $candidates = is_array($items) ? $items : iterator_to_array($items, false);

        if ($candidates === []) {
            return;
        }

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof RemoteCatalogItemCandidate) {
                throw new InvalidArgumentException('Remote catalogue append accepts RemoteCatalogItemCandidate values only.');
            }
        }

        DB::transaction(function () use ($scan, $candidates): void {
            $lockedScan = $this->lockRunningScan($scan);
            $snapshot = RemoteCatalogSnapshot::withoutWorkspaceScope()
                ->where('workspace_id', $lockedScan->workspace_id)
                ->where('scan_id', $lockedScan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($snapshot->published_at !== null) {
                throw new RemoteCatalogScanStateException('Cannot append to a published remote catalogue snapshot.');
            }

            $now = now();
            $rows = array_map(static fn (RemoteCatalogItemCandidate $candidate): array => [
                'workspace_id' => $lockedScan->workspace_id,
                'snapshot_id' => $snapshot->id,
                'remote_identifier' => $candidate->remoteIdentifier,
                'sku' => $candidate->sku,
                'name' => $candidate->name,
                'remote_type' => $candidate->remoteType,
                'remote_status' => $candidate->remoteStatus,
                'remote_updated_at' => $candidate->remoteUpdatedAt,
                'thumbnail_locator' => $candidate->thumbnailLocator,
                'storefront_locator' => $candidate->storefrontLocator === null
                    ? null
                    : json_encode($candidate->storefrontLocator, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ], $candidates);

            RemoteCatalogSnapshotItem::withoutWorkspaceScope()->insert($rows);
            $lockedScan->increment('received_item_count', count($rows));
        });
    }

    public function publish(RemoteCatalogScan $scan): RemoteCatalogSnapshot
    {
        try {
            return DB::transaction(function () use ($scan): RemoteCatalogSnapshot {
                $lockedScan = $this->lockRunningScan($scan);

                ConnectorAccount::withoutWorkspaceScope()
                    ->where('workspace_id', $lockedScan->workspace_id)
                    ->whereKey($lockedScan->connector_account_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $snapshot = RemoteCatalogSnapshot::withoutWorkspaceScope()
                    ->where('workspace_id', $lockedScan->workspace_id)
                    ->where('scan_id', $lockedScan->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $itemCount = RemoteCatalogSnapshotItem::withoutWorkspaceScope()
                    ->where('workspace_id', $lockedScan->workspace_id)
                    ->where('snapshot_id', $snapshot->id)
                    ->count();

                if ($lockedScan->expected_item_count !== null && $itemCount !== $lockedScan->expected_item_count) {
                    throw new RemoteCatalogScanIncompleteException(sprintf(
                        'Remote catalogue scan expected %d items but persisted %d.',
                        $lockedScan->expected_item_count,
                        $itemCount,
                    ));
                }

                if ($itemCount !== $lockedScan->received_item_count) {
                    throw new RemoteCatalogScanIncompleteException(sprintf(
                        'Remote catalogue scan recorded %d received items but persisted %d.',
                        $lockedScan->received_item_count,
                        $itemCount,
                    ));
                }

                $current = RemoteCatalogCurrentSnapshot::withoutWorkspaceScope()
                    ->where('workspace_id', $lockedScan->workspace_id)
                    ->where('connector_account_id', $lockedScan->connector_account_id)
                    ->where('data_domain', $lockedScan->data_domain->value)
                    ->lockForUpdate()
                    ->first();

                $previousSnapshot = $current === null
                    ? null
                    : RemoteCatalogSnapshot::withoutWorkspaceScope()->findOrFail($current->snapshot_id);

                if ($previousSnapshot !== null) {
                    $previousScan = RemoteCatalogScan::withoutWorkspaceScope()->findOrFail($previousSnapshot->scan_id);

                    if ($previousScan->generation > $lockedScan->generation) {
                        throw new StaleRemoteCatalogScanException(
                            'An older remote catalogue scan cannot supersede a newer published snapshot.',
                        );
                    }
                }

                $capturedAt = now();
                $snapshot->update([
                    'previous_snapshot_id' => $previousSnapshot?->id,
                    'item_count' => $itemCount,
                    'captured_at' => $capturedAt,
                    'published_at' => $capturedAt,
                ]);

                if ($current === null) {
                    RemoteCatalogCurrentSnapshot::withoutWorkspaceScope()->create([
                        'workspace_id' => $lockedScan->workspace_id,
                        'connector_account_id' => $lockedScan->connector_account_id,
                        'data_domain' => $lockedScan->data_domain,
                        'snapshot_id' => $snapshot->id,
                    ]);
                } else {
                    $current->update(['snapshot_id' => $snapshot->id]);
                }

                $lockedScan->update([
                    'status' => RemoteCatalogScanStatus::Succeeded,
                    'finished_at' => $capturedAt,
                    'failure_code' => null,
                    'failure_detail' => null,
                ]);

                return RemoteCatalogSnapshot::withoutWorkspaceScope()->findOrFail($snapshot->id);
            });
        } catch (RemoteCatalogScanIncompleteException|StaleRemoteCatalogScanException $exception) {
            $this->fail($scan, $exception instanceof StaleRemoteCatalogScanException ? 'stale_scan' : 'incomplete_scan', $exception->getMessage());

            throw $exception;
        }
    }

    public function fail(RemoteCatalogScan $scan, string $failureCode, ?string $failureDetail = null): void
    {
        DB::transaction(function () use ($scan, $failureCode, $failureDetail): void {
            $lockedScan = RemoteCatalogScan::withoutWorkspaceScope()
                ->where('workspace_id', $scan->workspace_id)
                ->whereKey($scan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedScan->status !== RemoteCatalogScanStatus::Running) {
                return;
            }

            $lockedScan->update([
                'status' => RemoteCatalogScanStatus::Failed,
                'failure_code' => $failureCode,
                'failure_detail' => $failureDetail,
                'finished_at' => now(),
            ]);
        });
    }

    private function lockRunningScan(RemoteCatalogScan $scan): RemoteCatalogScan
    {
        $lockedScan = RemoteCatalogScan::withoutWorkspaceScope()
            ->where('workspace_id', $scan->workspace_id)
            ->whereKey($scan->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($lockedScan->status !== RemoteCatalogScanStatus::Running) {
            throw new RemoteCatalogScanStateException(
                'Remote catalogue scan is not running: '.$lockedScan->status->value,
            );
        }

        return $lockedScan;
    }

    /**
     * @param  array<string, mixed>  $targetContext
     * @return array<string, mixed>
     */
    private function normalizeTargetContext(array $targetContext): array
    {
        if ($targetContext === []) {
            throw new InvalidArgumentException('Remote catalogue target context must not be empty.');
        }

        $normalized = $this->sortRecursively($targetContext);
        $this->assertNonSecretTargetContext($normalized);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function sortRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursively($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @param array<string, mixed> $targetContext */
    private function assertNonSecretTargetContext(array $targetContext): void
    {
        $walk = function (array $value) use (&$walk): void {
            foreach ($value as $key => $item) {
                $normalizedKey = strtolower((string) $key);

                foreach (['secret', 'password', 'credential', 'access_token', 'consumer_key', 'token_secret'] as $forbidden) {
                    if (str_contains($normalizedKey, $forbidden)) {
                        throw new InvalidArgumentException(
                            'Remote catalogue target context must contain non-secret target identity only.',
                        );
                    }
                }

                if (is_array($item)) {
                    $walk($item);
                }
            }
        };

        $walk($targetContext);
    }
}
