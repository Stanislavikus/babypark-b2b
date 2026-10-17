<?php

namespace App\Services\Connectors;

use App\Enums\SyncDataDomain;
use App\Models\ConnectorAccount;
use App\Models\RemoteCatalogCurrentSnapshot;
use App\Models\RemoteCatalogSnapshot;

final class RemoteCatalogCurrentSnapshotResolver
{
    /**
     * @param  array<string, mixed>  $currentTargetContext
     */
    public function resolve(
        ConnectorAccount $account,
        SyncDataDomain $dataDomain,
        array $currentTargetContext,
    ): ?RemoteCatalogSnapshot {
        $pointer = RemoteCatalogCurrentSnapshot::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('data_domain', $dataDomain->value)
            ->first();

        if ($pointer === null) {
            return null;
        }

        $snapshot = RemoteCatalogSnapshot::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('data_domain', $dataDomain->value)
            ->whereKey($pointer->snapshot_id)
            ->first();

        if ($snapshot === null || $snapshot->published_at === null) {
            return null;
        }

        return $this->canonicalJson($snapshot->target_context) === $this->canonicalJson($currentTargetContext)
            ? $snapshot
            : null;
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        $sort = function (array $input) use (&$sort): array {
            foreach ($input as $key => $item) {
                if (is_array($item)) {
                    $input[$key] = $sort($item);
                }
            }

            if (! array_is_list($input)) {
                ksort($input);
            }

            return $input;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }
}
