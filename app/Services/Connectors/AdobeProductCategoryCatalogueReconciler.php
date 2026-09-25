<?php

namespace App\Services\Connectors;

use App\Models\AdobeProductCategory;
use App\Models\AdobeProductCategoryCatalogueState;
use App\Models\ConnectorAccount;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshot;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogCategoryCatalogue;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogTargetChangedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdobeProductCategoryCatalogueReconciler
{
    public function __construct(
        private readonly AdobeConnectorAccountTargetSnapshotResolver $targetResolver,
    ) {}

    public function reconcile(
        ConnectorAccount $account,
        AdobeConnectorAccountTargetSnapshot $capturedTarget,
        AdobeRemoteCatalogCategoryCatalogue $catalogue,
    ): void {
        DB::transaction(function () use ($account, $capturedTarget, $catalogue): void {
            $lockedAccount = ConnectorAccount::withoutWorkspaceScope()
                ->where('workspace_id', $account->workspace_id)
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $capturedTarget->equals($this->targetResolver->resolve($lockedAccount))) {
                throw new AdobeRemoteCatalogTargetChangedException(
                    'Adobe category catalogue target changed while the catalogue was being read.',
                );
            }

            $scope = [
                'workspace_id' => (string) $lockedAccount->workspace_id,
                'connector_account_id' => (string) $lockedAccount->id,
            ];

            $existing = AdobeProductCategory::withoutWorkspaceScope()
                ->where($scope)
                ->get()
                ->keyBy('external_category_id');

            AdobeProductCategory::withoutWorkspaceScope()
                ->where($scope)
                ->whereNull('missing_since')
                ->update(['missing_since' => $catalogue->capturedAt]);

            $rows = [];

            foreach ($catalogue->categories as $category) {
                $externalId = $category['external_category_id'];
                $previous = $existing->get($externalId);

                $rows[] = [
                    ...$scope,
                    'id' => $previous?->id ?? (string) Str::uuid(),
                    'external_category_id' => $externalId,
                    'parent_external_category_id' => $category['parent_external_category_id'],
                    'name' => $category['name'],
                    'provider_path' => $category['provider_path'],
                    'breadcrumb' => $category['breadcrumb'],
                    'level' => $category['level'],
                    'position' => $category['position'],
                    'is_active' => $category['is_active'],
                    'first_seen_at' => $previous?->first_seen_at ?? $catalogue->capturedAt,
                    'last_seen_at' => $catalogue->capturedAt,
                    'missing_since' => null,
                    'created_at' => $previous?->created_at ?? $catalogue->capturedAt,
                    'updated_at' => $catalogue->capturedAt,
                ];
            }

            if ($rows !== []) {
                DB::table('adobe_product_categories')->upsert(
                    $rows,
                    ['workspace_id', 'connector_account_id', 'external_category_id'],
                    [
                        'parent_external_category_id',
                        'name',
                        'provider_path',
                        'breadcrumb',
                        'level',
                        'position',
                        'is_active',
                        'last_seen_at',
                        'missing_since',
                        'updated_at',
                    ],
                );
            }

            AdobeProductCategoryCatalogueState::withoutWorkspaceScope()->updateOrCreate(
                $scope,
                [
                    'category_count' => count($catalogue->categories),
                    'target_context' => $capturedTarget->toEnvelopeArray(),
                    'last_successful_synced_at' => $catalogue->capturedAt,
                ],
            );
        }, 3);
    }
}
