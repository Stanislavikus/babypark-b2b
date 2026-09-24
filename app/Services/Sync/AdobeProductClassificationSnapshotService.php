<?php

namespace App\Services\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Support\Sync\AdobeProductEffectiveClassification;

final class AdobeProductClassificationSnapshotService
{
    private const string REVISION_PREFIX = 'platform.adobe-product-classification-revision.v1';

    public function __construct(
        private readonly AdobeProductClassificationReadService $readService,
    ) {}

    public function isApplicable(
        ConnectorAccount $account,
        SyncConfiguration $configuration,
        SyncSemanticOperation $semanticOperation,
    ): bool {
        if ($configuration->workspace_id !== $account->workspace_id
            || $configuration->connector_account_id !== $account->id
            || $configuration->data_domain !== SyncDataDomain::Products
            || $semanticOperation !== SyncSemanticOperation::Export
        ) {
            return false;
        }

        $definitionCode = $account->relationLoaded('connectorDefinition')
            ? $account->connectorDefinition?->code
            : $account->connectorDefinition()->value('code');

        return $definitionCode === 'adobe_commerce';
    }

    /**
     * @param  list<int>  $productIds
     * @return list<array<string, mixed>>|null
     */
    public function payload(
        ConnectorAccount $account,
        SyncConfiguration $configuration,
        SyncSemanticOperation $semanticOperation,
        array $productIds,
    ): ?array {
        if (! $this->isApplicable($account, $configuration, $semanticOperation)) {
            return null;
        }

        $ids = array_values(array_unique(array_map('intval', $productIds)));
        sort($ids, SORT_NUMERIC);

        $resolved = $this->readService->resolveForProductIds($account, $ids);
        $payload = [];

        foreach ($ids as $productId) {
            $classification = $resolved[(string) $productId] ?? null;

            if (! $classification instanceof AdobeProductEffectiveClassification) {
                $payload[] = [
                    'product_id' => (string) $productId,
                    'category_source' => 'unresolved',
                    'external_category_ids' => [],
                    'attribute_set_source' => 'unresolved',
                    'provider_attribute_set_id' => null,
                    'has_trusted_remote_subject' => false,
                    'blockers' => ['product_unavailable'],
                    'advisories' => [],
                ];

                continue;
            }

            $payload[] = $classification->toSnapshotArray();
        }

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     */
    public function revisionFromPayload(array $payload): string
    {
        $canonical = array_values($payload);

        usort(
            $canonical,
            static fn (array $left, array $right): int => ((int) ($left['product_id'] ?? 0))
                <=> ((int) ($right['product_id'] ?? 0)),
        );

        return hash(
            'sha256',
            self::REVISION_PREFIX."\n".json_encode(
                $canonical,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * @param  list<int>  $productIds
     */
    public function revision(
        ConnectorAccount $account,
        SyncConfiguration $configuration,
        SyncSemanticOperation $semanticOperation,
        array $productIds,
    ): ?string {
        $payload = $this->payload($account, $configuration, $semanticOperation, $productIds);

        return $payload === null ? null : $this->revisionFromPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    public function classificationForProduct(int $productId, array $snapshot): ?array
    {
        $classifications = $snapshot['adobe_product_classifications'] ?? null;

        if (! is_array($classifications) || ! array_is_list($classifications)) {
            return null;
        }

        foreach ($classifications as $classification) {
            if (! is_array($classification)) {
                continue;
            }

            if ((int) ($classification['product_id'] ?? 0) === $productId) {
                return $classification;
            }
        }

        return null;
    }
}
