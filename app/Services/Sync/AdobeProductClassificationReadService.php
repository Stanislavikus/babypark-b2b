<?php

namespace App\Services\Sync;

use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetOverride;
use App\Models\AdobeProductCategoryOverride;
use App\Models\AdobeProductTypeAttributeSetDefault;
use App\Models\ConnectorAccount;
use App\Models\ConnectorCategoryMapping;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\RemoteCatalogSnapshotItem;
use App\Services\Connectors\AdobeRemoteCatalogProjectionService;
use App\Support\Sync\AdobeProductEffectiveClassification;
use App\Support\Sync\Exceptions\AdobeProductClassificationException;
use Illuminate\Support\Collection;

final class AdobeProductClassificationReadService
{
    public function __construct(
        private readonly AdobeRemoteCatalogProjectionService $remoteCatalogProjection,
    ) {}

    public function resolve(ConnectorAccount $account, Product $product): AdobeProductEffectiveClassification
    {
        $resolved = $this->resolveForProductIds($account, [(int) $product->id]);

        if (! isset($resolved[(string) $product->id])) {
            throw AdobeProductClassificationException::productUnavailable();
        }

        return $resolved[(string) $product->id];
    }

    /**
     * @param  list<int>  $productIds
     * @return array<string, AdobeProductEffectiveClassification> keyed by Product ID
     */
    public function resolveForProductIds(ConnectorAccount $account, array $productIds): array
    {
        $this->assertAdobeAccount($account);

        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        $products = Product::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->whereIn('id', $productIds)
            ->with(['variants' => static fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('id')])
            ->orderBy('id')
            ->get();

        if ($products->isEmpty()) {
            return [];
        }

        $loadedProductIds = $products->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $productTypeIds = $products->pluck('product_type_id')->filter()->unique()->values()->all();
        $categoryIds = $products->pluck('category_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $variantIds = $products
            ->flatMap(static fn (Product $product) => $product->variants->pluck('id'))
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $attributeOverrides = AdobeProductAttributeSetOverride::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->whereIn('product_id', $loadedProductIds)
            ->get()
            ->keyBy('product_id');

        $typeDefaults = $productTypeIds === []
            ? collect()
            : AdobeProductTypeAttributeSetDefault::withoutWorkspaceScope()
                ->where('workspace_id', $account->workspace_id)
                ->where('connector_account_id', $account->id)
                ->whereIn('product_type_id', $productTypeIds)
                ->get()
                ->keyBy('product_type_id');

        $categoryOverrides = AdobeProductCategoryOverride::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->whereIn('product_id', $loadedProductIds)
            ->orderBy('external_category_id')
            ->get()
            ->groupBy('product_id');

        $categoryMappings = $categoryIds === []
            ? collect()
            : ConnectorCategoryMapping::withoutWorkspaceScope()
                ->where('workspace_id', $account->workspace_id)
                ->where('connector_account_id', $account->id)
                ->whereIn('category_id', $categoryIds)
                ->get()
                ->keyBy('category_id');

        $links = ExternalRecordLink::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where(function ($query) use ($loadedProductIds, $variantIds): void {
                $query->whereIn('product_id', $loadedProductIds);

                if ($variantIds !== []) {
                    $query->orWhereIn('product_variant_id', $variantIds);
                }
            })
            ->get();

        $trustedByProduct = $links
            ->filter(static fn (ExternalRecordLink $link): bool => $link->hasMerchantConfirmedTrust())
            ->groupBy('product_id');
        $trustedByVariant = $links
            ->filter(static fn (ExternalRecordLink $link): bool => $link->hasMerchantConfirmedTrust())
            ->groupBy('product_variant_id');

        $trustedDiscriminators = $links
            ->filter(static fn (ExternalRecordLink $link): bool => $link->hasMerchantConfirmedTrust())
            ->pluck('external_record_discriminator')
            ->filter(static fn ($value): bool => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();

        $snapshot = $this->remoteCatalogProjection->currentSnapshot($account);
        $remoteItemsByIdentifier = collect();

        if ($snapshot !== null && $trustedDiscriminators !== []) {
            $remoteItemsByIdentifier = RemoteCatalogSnapshotItem::withoutWorkspaceScope()
                ->where('workspace_id', $account->workspace_id)
                ->where('snapshot_id', $snapshot->id)
                ->whereIn('remote_identifier', $trustedDiscriminators)
                ->get()
                ->keyBy('remote_identifier');
        }

        $configuredSetIds = $attributeOverrides->pluck('adobe_product_attribute_set_id')
            ->merge($typeDefaults->pluck('adobe_product_attribute_set_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $observedProviderSetIds = $remoteItemsByIdentifier
            ->pluck('external_attribute_set_id')
            ->filter(static fn ($id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $setsById = $configuredSetIds === []
            ? collect()
            : AdobeProductAttributeSet::withoutWorkspaceScope()
                ->where('workspace_id', $account->workspace_id)
                ->where('connector_account_id', $account->id)
                ->whereIn('id', $configuredSetIds)
                ->get()
                ->keyBy('id');

        $setsByProviderId = $observedProviderSetIds === []
            ? collect()
            : AdobeProductAttributeSet::withoutWorkspaceScope()
                ->where('workspace_id', $account->workspace_id)
                ->where('connector_account_id', $account->id)
                ->whereIn('provider_attribute_set_id', $observedProviderSetIds)
                ->get()
                ->keyBy('provider_attribute_set_id');

        $resolved = [];

        foreach ($products as $product) {
            $resolved[(string) $product->id] = $this->resolveLoadedProduct(
                $product,
                $categoryOverrides,
                $categoryMappings,
                $attributeOverrides,
                $typeDefaults,
                $setsById,
                $setsByProviderId,
                $trustedByProduct,
                $trustedByVariant,
                $remoteItemsByIdentifier,
            );
        }

        return $resolved;
    }

    private function resolveLoadedProduct(
        Product $product,
        Collection $categoryOverrides,
        Collection $categoryMappings,
        Collection $attributeOverrides,
        Collection $typeDefaults,
        Collection $setsById,
        Collection $setsByProviderId,
        Collection $trustedByProduct,
        Collection $trustedByVariant,
        Collection $remoteItemsByIdentifier,
    ): AdobeProductEffectiveClassification {
        [$externalCategoryIds, $categorySource, $categoryBlockers] = $this->resolveCategories(
            $product,
            $categoryOverrides,
            $categoryMappings,
        );

        $override = $attributeOverrides->get($product->id);
        $default = $typeDefaults->get($product->product_type_id);
        $overrideSet = $override !== null ? $setsById->get($override->adobe_product_attribute_set_id) : null;
        $defaultSet = $default !== null ? $setsById->get($default->adobe_product_attribute_set_id) : null;

        [$trustedLinks, $trustedBlockers] = $this->trustedLinksForProduct(
            $product,
            $trustedByProduct,
            $trustedByVariant,
        );

        $attributeBlockers = $trustedBlockers;
        $advisories = [];
        $effectiveSet = null;
        $attributeSetSource = 'unresolved';

        if ($trustedLinks !== []) {
            $observedIds = [];

            foreach ($trustedLinks as $link) {
                $remote = $remoteItemsByIdentifier->get((string) $link->external_record_discriminator);
                $providerSetId = $remote?->external_attribute_set_id;

                if (! is_int($providerSetId) && ! ctype_digit((string) $providerSetId)) {
                    $attributeBlockers[] = 'trusted_remote_attribute_set_unresolved';

                    continue;
                }

                $observedIds[] = (int) $providerSetId;
            }

            $observedIds = array_values(array_unique($observedIds));

            if ($attributeBlockers === [] && count($observedIds) > 1) {
                $attributeBlockers[] = 'trusted_remote_attribute_set_conflict';
            }

            if ($attributeBlockers === [] && count($observedIds) === 1) {
                $effectiveSet = $setsByProviderId->get($observedIds[0]);

                if (! $effectiveSet instanceof AdobeProductAttributeSet || $effectiveSet->missing_since !== null) {
                    $effectiveSet = null;
                    $attributeBlockers[] = 'trusted_remote_attribute_set_unresolved';
                } else {
                    $attributeSetSource = 'observed_remote';

                    if ($overrideSet instanceof AdobeProductAttributeSet
                        && $overrideSet->provider_attribute_set_id !== $effectiveSet->provider_attribute_set_id
                    ) {
                        $advisories[] = 'product_override_differs_from_observed_remote';
                    }

                    if ($defaultSet instanceof AdobeProductAttributeSet
                        && $defaultSet->provider_attribute_set_id !== $effectiveSet->provider_attribute_set_id
                    ) {
                        $advisories[] = 'product_type_default_differs_from_observed_remote';
                    }
                }
            }
        } elseif ($attributeBlockers === []) {
            if ($override !== null) {
                if ($overrideSet instanceof AdobeProductAttributeSet && $overrideSet->missing_since === null) {
                    $effectiveSet = $overrideSet;
                    $attributeSetSource = 'product_override';
                } else {
                    $attributeBlockers[] = 'product_attribute_set_override_unavailable';
                }
            } elseif ($default !== null) {
                if ($defaultSet instanceof AdobeProductAttributeSet && $defaultSet->missing_since === null) {
                    $effectiveSet = $defaultSet;
                    $attributeSetSource = 'product_type_default';
                } else {
                    $attributeBlockers[] = 'product_type_attribute_set_default_unavailable';
                }
            } else {
                $attributeBlockers[] = 'attribute_set_unresolved';
            }
        }

        $blockers = array_values(array_unique(array_merge($categoryBlockers, $attributeBlockers)));
        sort($blockers);
        $advisories = array_values(array_unique($advisories));
        sort($advisories);

        return new AdobeProductEffectiveClassification(
            productId: (string) $product->id,
            externalCategoryIds: $externalCategoryIds,
            categorySource: $categorySource,
            adobeProductAttributeSetId: $effectiveSet?->id,
            providerAttributeSetId: $effectiveSet?->provider_attribute_set_id,
            attributeSetName: $effectiveSet?->name,
            attributeSetSource: $attributeSetSource,
            hasTrustedRemoteSubject: $trustedLinks !== [],
            blockers: $blockers,
            advisories: $advisories,
        );
    }

    /**
     * @return array{0:list<string>,1:string,2:list<string>}
     */
    private function resolveCategories(
        Product $product,
        Collection $categoryOverrides,
        Collection $categoryMappings,
    ): array {
        $overrides = $categoryOverrides->get($product->id, collect());

        if ($overrides->isNotEmpty()) {
            $ids = $overrides
                ->pluck('external_category_id')
                ->map(static fn ($value): string => trim((string) $value))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            return [$ids, 'product_override', $ids === [] ? ['category_unresolved'] : []];
        }

        if ($product->category_id !== null) {
            $mapping = $categoryMappings->get($product->category_id);
            $externalCategoryId = trim((string) ($mapping?->external_category_id ?? ''));

            if ($externalCategoryId !== '') {
                return [[$externalCategoryId], 'category_mapping', []];
            }
        }

        return [[], 'unresolved', ['category_unresolved']];
    }

    /**
     * @return array{0:list<ExternalRecordLink>,1:list<string>}
     */
    private function trustedLinksForProduct(
        Product $product,
        Collection $trustedByProduct,
        Collection $trustedByVariant,
    ): array {
        $parentLinks = $trustedByProduct->get($product->id, collect())->values();

        if ($parentLinks->count() > 1) {
            return [[], ['trusted_remote_subject_ambiguous']];
        }

        if ($parentLinks->count() === 1) {
            return [[$parentLinks->first()], []];
        }

        $trustedVariantLinks = [];

        foreach ($product->variants as $variant) {
            $variantLinks = $trustedByVariant->get($variant->id, collect())->values();

            if ($variantLinks->count() > 1) {
                return [[], ['trusted_remote_subject_ambiguous']];
            }

            if ($variantLinks->count() === 1) {
                $trustedVariantLinks[] = $variantLinks->first();
            }
        }

        return [$trustedVariantLinks, []];
    }

    private function assertAdobeAccount(ConnectorAccount $account): void
    {
        $definitionCode = $account->relationLoaded('connectorDefinition')
            ? $account->connectorDefinition?->code
            : $account->connectorDefinition()->value('code');

        if ($definitionCode !== 'adobe_commerce') {
            throw AdobeProductClassificationException::nonAdobeAccount();
        }
    }
}
