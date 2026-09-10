<?php

namespace App\Support\Connectors\AdobePaaS\EntityTrust;

use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductObservedState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductParentDesiredState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductParentObservedState;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductRemoteMediaMetadataEntry;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductRemoteMediaMetadataReader;
use App\Support\Sync\EntityTrust\EntityTrustControlledFieldComparison;
use App\Support\Sync\EntityTrust\EntityTrustMediaSummary;
use App\Support\Sync\Preview\ProductExecutionImageInput;

final class AdobeProductEntityTrustComparisonBuilder
{
    public function __construct(
        private readonly AdobeProductRemoteMediaMetadataReader $mediaMetadataReader,
    ) {}

    /**
     * @return list<EntityTrustControlledFieldComparison>
     */
    public function buildSimpleComparisons(
        AdobeProductDesiredState $desired,
        AdobeProductObservedState $observed,
    ): array {
        $comparisons = [
            new EntityTrustControlledFieldComparison('sku', 'Артикул', $desired->sku, $observed->sku),
            new EntityTrustControlledFieldComparison('name', 'Назва', $desired->name, $observed->name),
            new EntityTrustControlledFieldComparison('type', 'Тип', $desired->typeId, $observed->typeId),
            new EntityTrustControlledFieldComparison('attribute_set', 'Набір атрибутів', (string) $desired->attributeSetId, (string) $observed->attributeSetId),
            new EntityTrustControlledFieldComparison('status', 'Статус', (string) $desired->status, (string) $observed->status),
            new EntityTrustControlledFieldComparison('visibility', 'Видимість', (string) $desired->visibility, (string) $observed->visibility),
            new EntityTrustControlledFieldComparison('price', 'Ціна', $this->formatPrice($desired->price), $this->formatPrice($observed->price)),
        ];

        foreach ($this->sortedCustomAttributeKeys($desired->customAttributes, $observed->customAttributes) as $key) {
            $comparisons[] = new EntityTrustControlledFieldComparison(
                'custom:'.$key,
                $key,
                $this->stringify($desired->customAttributes[$key] ?? null),
                $this->stringify($observed->customAttributes[$key] ?? null),
            );
        }

        return $comparisons;
    }

    /**
     * @return list<EntityTrustControlledFieldComparison>
     */
    public function buildParentComparisons(
        AdobeProductParentDesiredState $desired,
        AdobeProductParentObservedState $observed,
    ): array {
        $comparisons = [
            new EntityTrustControlledFieldComparison('sku', 'Артикул', $desired->sku, $observed->sku),
            new EntityTrustControlledFieldComparison('name', 'Назва', $desired->name, $observed->name),
            new EntityTrustControlledFieldComparison('type', 'Тип', $desired->typeId, $observed->typeId),
            new EntityTrustControlledFieldComparison('attribute_set', 'Набір атрибутів', (string) $desired->attributeSetId, (string) $observed->attributeSetId),
            new EntityTrustControlledFieldComparison('status', 'Статус', (string) $desired->status, (string) $observed->status),
            new EntityTrustControlledFieldComparison('visibility', 'Видимість', (string) $desired->visibility, (string) $observed->visibility),
        ];

        foreach ($this->sortedCustomAttributeKeys($desired->customAttributes, $observed->customAttributes) as $key) {
            $comparisons[] = new EntityTrustControlledFieldComparison(
                'custom:'.$key,
                $key,
                $this->stringify($desired->customAttributes[$key] ?? null),
                $this->stringify($observed->customAttributes[$key] ?? null),
            );
        }

        return $comparisons;
    }

    public function buildPlatformMediaSummary(?ProductExecutionImageInput $imageInput): EntityTrustMediaSummary
    {
        if ($imageInput === null || $imageInput->entries === []) {
            return new EntityTrustMediaSummary(0, 'немає зображень', null, null);
        }

        $roles = [];
        $primary = false;

        foreach ($imageInput->entries as $index => $entry) {
            if ($index === 0) {
                $primary = true;
            } else {
                $roles[] = 'галерея';
            }
        }

        $roleSummary = $primary
            ? (count($imageInput->entries) > 1 ? 'основне + галерея' : 'основне')
            : 'галерея';

        return new EntityTrustMediaSummary(
            declaredImageCount: count($imageInput->entries),
            declaredRolesSummary: $roleSummary,
            remoteImageEntryCount: null,
            remoteRolesSummary: null,
        );
    }

    /**
     * @param  array<string, mixed>  $productPayload
     */
    public function buildRemoteMediaSummaryFromProductPayload(array $productPayload): EntityTrustMediaSummary
    {
        $index = $this->mediaMetadataReader->read($productPayload);

        if (! $index->isTrusted()) {
            return new EntityTrustMediaSummary(0, 'немає зображень', 0, 'невідомо');
        }

        $hasPrimary = false;
        $galleryCount = 0;

        foreach ($index->entries as $entry) {
            if ($this->entryIsPrimary($entry)) {
                $hasPrimary = true;
            } else {
                $galleryCount++;
            }
        }

        $roleSummary = $hasPrimary
            ? ($galleryCount > 0 ? 'основне + галерея' : 'основне')
            : ($galleryCount > 0 ? 'галерея' : 'немає зображень');

        return new EntityTrustMediaSummary(
            declaredImageCount: 0,
            declaredRolesSummary: '—',
            remoteImageEntryCount: count($index->entries),
            remoteRolesSummary: $roleSummary,
        );
    }

    /**
     * @param  list<EntityTrustControlledFieldComparison>  $comparisons
     */
    public function fingerprintComparisons(array $comparisons): string
    {
        $normalized = array_map(
            static fn (EntityTrustControlledFieldComparison $comparison): array => [
                'field' => $comparison->fieldKey,
                'platform' => $comparison->platformValue,
                'remote' => $comparison->remoteValue,
            ],
            $comparisons,
        );

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $desiredCustom
     * @param  array<string, mixed>  $observedCustom
     * @return list<string>
     */
    private function sortedCustomAttributeKeys(array $desiredCustom, array $observedCustom): array
    {
        $keys = array_unique(array_merge(array_keys($desiredCustom), array_keys($observedCustom)));
        sort($keys);

        return $keys;
    }

    private function formatPrice(float $price): string
    {
        return number_format($price, 2, '.', '');
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function entryIsPrimary(AdobeProductRemoteMediaMetadataEntry $entry): bool
    {
        foreach ($entry->types as $type) {
            if (in_array($type, ['image', 'small_image', 'thumbnail'], true)) {
                return true;
            }
        }

        return false;
    }
}
