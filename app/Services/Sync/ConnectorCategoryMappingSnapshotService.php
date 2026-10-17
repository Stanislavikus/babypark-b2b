<?php

namespace App\Services\Sync;

use App\Models\ConnectorCategoryMapping;

final class ConnectorCategoryMappingSnapshotService
{
    private const string REVISION_PREFIX = 'platform.connector-category-mapping-revision.v1';

    /**
     * @return list<array{category_id: int, external_category_id: string}>
     */
    public function payload(string $workspaceId, string $connectorAccountId): array
    {
        return ConnectorCategoryMapping::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->orderBy('category_id')
            ->orderBy('external_category_id')
            ->get(['category_id', 'external_category_id'])
            ->map(static fn (ConnectorCategoryMapping $mapping): array => [
                'category_id' => (int) $mapping->category_id,
                'external_category_id' => (string) $mapping->external_category_id,
            ])
            ->all();
    }

    public function revision(string $workspaceId, string $connectorAccountId): string
    {
        return $this->revisionFromPayload($this->payload($workspaceId, $connectorAccountId));
    }

    /**
     * @param  list<array{category_id: int, external_category_id: string}>  $payload
     */
    public function revisionFromPayload(array $payload): string
    {
        $canonical = array_values($payload);

        usort(
            $canonical,
            static function (array $left, array $right): int {
                $categoryCompare = $left['category_id'] <=> $right['category_id'];

                if ($categoryCompare !== 0) {
                    return $categoryCompare;
                }

                return strcmp($left['external_category_id'], $right['external_category_id']);
            },
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
     * @param  array<string, mixed>  $snapshot
     */
    public function externalCategoryIdFor(?int $categoryId, array $snapshot): ?string
    {
        if ($categoryId === null) {
            return null;
        }

        $mappings = $snapshot['category_mappings'] ?? [];

        if (! is_array($mappings) || ! array_is_list($mappings)) {
            return null;
        }

        foreach ($mappings as $mapping) {
            if (! is_array($mapping)) {
                continue;
            }

            $mappedCategoryId = $mapping['category_id'] ?? null;
            $externalCategoryId = $mapping['external_category_id'] ?? null;

            if ((int) $mappedCategoryId === $categoryId
                && is_string($externalCategoryId)
                && trim($externalCategoryId) !== ''
            ) {
                return trim($externalCategoryId);
            }
        }

        return null;
    }
}
