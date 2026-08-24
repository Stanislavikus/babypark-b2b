<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model;

use B2BPlatform\MagentoSafeSync\Api\Data\ProductWriteMappedAttributeInterface;
use B2BPlatform\MagentoSafeSync\Api\Data\ProductWriteRequestInterface;
use B2BPlatform\MagentoSafeSync\Api\Data\ProductWriteResponseInterface;
use B2BPlatform\MagentoSafeSync\Api\ProductWriteManagementInterface;
use B2BPlatform\MagentoSafeSync\Model\Connection\ConnectionQuarantine;
use B2BPlatform\MagentoSafeSync\Model\Data\ProductWriteResponseFactory;
use B2BPlatform\MagentoSafeSync\Model\Media\NonMediaProductWriteScope;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Media\Config as ProductMediaConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

final class ProductWriteManagement implements ProductWriteManagementInterface
{
    private const APPLIED_KNOWN_APPLIED = 'known_applied';

    private const APPLIED_KNOWN_NOT_APPLIED = 'known_not_applied';

    private const APPLIED_UNKNOWN_OR_AMBIGUOUS = 'unknown_or_ambiguous';

    private const PRICE_SCALE = 6;

    /** @var list<string> */
    private const RESERVED_CUSTOM_ATTRIBUTE_CODES = [
        'attribute_set_id',
        'categories',
        'category_ids',
        'entity_id',
        'id',
        'link_field',
        'media_gallery',
        'name',
        'price',
        'row_id',
        'sku',
        'status',
        'tier_price',
        'tier_prices',
        'type_id',
        'visibility',
        'website_ids',
        'websites',
    ];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly MetadataPool $metadataPool,
        private readonly ResourceConnection $resourceConnection,
        private readonly ProductWriteResponseFactory $responseFactory,
        private readonly ProductMediaConfig $productMediaConfig,
        private readonly GaleraWriteSession $galeraWriteSession,
        private readonly ConnectionQuarantine $connectionQuarantine,
        private readonly NonMediaProductWriteScope $nonMediaProductWriteScope,
        private readonly ProductEntityManagerCallbackBridge $callbackBridge,
        private readonly LoggerInterface $logger,
    ) {}

    public function writeSimpleProduct(
        int $logicalEntityId,
        ProductWriteRequestInterface $request,
    ): ProductWriteResponseInterface {
        $expectedSku = $request->getExpectedSku();

        if ($logicalEntityId <= 0) {
            return $this->knownNotApplied('safe_sync_invalid_logical_entity_id', $logicalEntityId, $expectedSku, false, 0);
        }

        if ($expectedSku === '') {
            return $this->knownNotApplied('safe_sync_invalid_expected_sku', $logicalEntityId, $expectedSku, false, 0);
        }

        $mutation = $this->buildMutation($request);

        if ($mutation['reason_code'] !== null) {
            return $this->knownNotApplied($mutation['reason_code'], $logicalEntityId, $expectedSku, false, 0);
        }

        if ($mutation['has_changes'] === false) {
            return $this->knownNotApplied('safe_sync_no_controlled_fields_requested', $logicalEntityId, $expectedSku, false, 0);
        }

        if ($mutation['price'] !== null && ! $this->hasSupportedPricePrecision($mutation['price'])) {
            return $this->knownNotApplied('safe_sync_invalid_price_precision', $logicalEntityId, $expectedSku, false, 0);
        }

        $metadata = $this->metadataPool->getMetadata(ProductInterface::class);
        $identifierField = (string) $metadata->getIdentifierField();
        $linkField = method_exists($metadata, 'getLinkField') ? (string) $metadata->getLinkField() : $identifierField;
        $entityTable = method_exists($metadata, 'getEntityTable') ? (string) $metadata->getEntityTable() : 'catalog_product_entity';
        $connection = $this->resolveEntityConnection($metadata);

        if ($this->transactionLevel($connection) !== 0) {
            return $this->knownNotApplied('safe_sync_bridge_transaction_state_unexpected', $logicalEntityId, $expectedSku, false, 0);
        }

        if (! $this->connectionQuarantine->isSupported($connection)) {
            return $this->knownNotApplied(
                'safe_sync_connection_quarantine_unavailable',
                $logicalEntityId,
                $expectedSku,
                false,
                0,
            );
        }

        if (! $this->hasLeadingIndexedColumn($connection, $entityTable, $identifierField)) {
            return $this->knownNotApplied('safe_sync_identifier_index_unavailable', $logicalEntityId, $expectedSku, false, 0);
        }

        if (! $this->hasLeadingIndexedColumn($connection, $entityTable, 'sku')) {
            return $this->knownNotApplied('safe_sync_sku_index_unavailable', $logicalEntityId, $expectedSku, false, 0);
        }

        try {
            $galeraState = $this->galeraWriteSession->establish($connection);
        } catch (\RuntimeException $exception) {
            $this->logger->warning('Safe Sync causal write session setup failed.', ['exception' => $exception]);

            return $this->knownNotApplied(
                $exception->getMessage(),
                $logicalEntityId,
                $expectedSku,
                false,
                0,
            );
        }

        $preCommitOutcome = $this->executeRollbackCapablePhase(
            $connection,
            $galeraState,
            $identifierField,
            $linkField,
            $entityTable,
            $logicalEntityId,
            $expectedSku,
            $mutation,
        );

        if ($preCommitOutcome instanceof ProductWriteResponseInterface) {
            return $preCommitOutcome;
        }

        $warningCodes = [];

        try {
            $this->callbackBridge->processPendingProductCallbacks();
        } catch (\Throwable $exception) {
            $warningCodes[] = 'safe_sync_post_commit_callback_failed';
            $this->logger->error('Safe Sync post-commit product callback failed.', ['exception' => $exception]);
        }

        try {
            $this->galeraWriteSession->restore($connection, $galeraState);
        } catch (\Throwable $exception) {
            $warningCodes[] = 'safe_sync_post_commit_galera_restore_failed';
            $warningCodes = array_values(array_unique(array_merge(
                $warningCodes,
                $this->warningCodesFromRestoreFailure($exception),
            )));
            $this->logger->error('Safe Sync post-commit Galera restore failed.', ['exception' => $exception]);
        }

        return $this->knownApplied(
            'safe_sync_simple_product_write_applied',
            $logicalEntityId,
            $preCommitOutcome['observed_sku'],
            true,
            $preCommitOutcome['consequential_write_attempts'],
            $warningCodes,
        );
    }

    /**
     * @return array{
     *   has_changes:bool,
     *   reason_code:?string,
     *   name:?string,
     *   status:?int,
     *   visibility:?int,
     *   price:?float,
     *   mapped_attributes:list<array{attribute_code:string,value:string}>
     * }
     */
    private function buildMutation(ProductWriteRequestInterface $request): array
    {
        $mappedAttributes = [];
        $forbiddenMediaAttributeCodes = $this->forbiddenMediaAttributeCodes();

        if ($forbiddenMediaAttributeCodes === null) {
            return $this->invalidMutation('safe_sync_media_attribute_capability_unavailable');
        }

        foreach ($request->getMappedAttributes() as $entry) {
            if (! $entry instanceof ProductWriteMappedAttributeInterface) {
                return $this->invalidMutation('safe_sync_invalid_mapped_attribute_payload');
            }

            $attributeCode = $entry->getAttributeCode();
            $value = $entry->getValue();

            if (! is_string($attributeCode) || ! is_string($value) || $attributeCode === '') {
                return $this->invalidMutation('safe_sync_invalid_mapped_attribute_payload');
            }

            if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $attributeCode) !== 1) {
                return $this->invalidMutation('safe_sync_invalid_mapped_attribute_code');
            }

            if (in_array(strtolower($attributeCode), self::RESERVED_CUSTOM_ATTRIBUTE_CODES, true)) {
                return $this->invalidMutation('safe_sync_reserved_mapped_attribute_code');
            }

            if (isset($forbiddenMediaAttributeCodes[strtolower($attributeCode)])) {
                return $this->invalidMutation('safe_sync_media_attribute_not_allowed');
            }

            $mappedAttributes[] = [
                'attribute_code' => $attributeCode,
                'value' => $value,
            ];
        }

        return [
            'has_changes' => $request->getName() !== null
                || $request->getStatus() !== null
                || $request->getVisibility() !== null
                || $request->getPrice() !== null
                || $mappedAttributes !== [],
            'reason_code' => null,
            'name' => $request->getName(),
            'status' => $request->getStatus(),
            'visibility' => $request->getVisibility(),
            'price' => $request->getPrice(),
            'mapped_attributes' => $mappedAttributes,
        ];
    }

    /**
     * @return array{
     *   has_changes:bool,
     *   reason_code:string,
     *   name:?string,
     *   status:?int,
     *   visibility:?int,
     *   price:?float,
     *   mapped_attributes:list<array{attribute_code:string,value:string}>
     * }
     */
    private function invalidMutation(string $reasonCode): array
    {
        return [
            'has_changes' => false,
            'reason_code' => $reasonCode,
            'name' => null,
            'status' => null,
            'visibility' => null,
            'price' => null,
            'mapped_attributes' => [],
        ];
    }

    /**
     * @return array<string, true>|null
     */
    private function forbiddenMediaAttributeCodes(): ?array
    {
        try {
            $mediaAttributeCodes = $this->productMediaConfig->getMediaAttributeCodes();
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($mediaAttributeCodes)) {
            return null;
        }

        $forbiddenCodes = [];

        foreach ($mediaAttributeCodes as $mediaAttributeCode) {
            if (! is_string($mediaAttributeCode) || $mediaAttributeCode === '') {
                return null;
            }

            $normalizedCode = strtolower($mediaAttributeCode);
            $forbiddenCodes[$normalizedCode] = true;
            $forbiddenCodes[$normalizedCode.'_label'] = true;
        }

        return $forbiddenCodes;
    }

    /**
     * @param  array{
     *   name:?string,
     *   status:?int,
     *   visibility:?int,
     *   price:?float,
     *   mapped_attributes:list<array{attribute_code:string,value:string}>
     * }  $mutation
     */
    private function applyMutation(object $product, array $mutation): void
    {
        if ($mutation['name'] !== null && method_exists($product, 'setName')) {
            $product->setName($mutation['name']);
        }

        if ($mutation['status'] !== null && method_exists($product, 'setStatus')) {
            $product->setStatus($mutation['status']);
        }

        if ($mutation['visibility'] !== null && method_exists($product, 'setVisibility')) {
            $product->setVisibility($mutation['visibility']);
        }

        if ($mutation['price'] !== null && method_exists($product, 'setPrice')) {
            $product->setPrice($mutation['price']);
        }

        foreach ($mutation['mapped_attributes'] as $attribute) {
            if (method_exists($product, 'setCustomAttribute')) {
                $product->setCustomAttribute($attribute['attribute_code'], $attribute['value']);
            } elseif (method_exists($product, 'setData')) {
                $product->setData($attribute['attribute_code'], $attribute['value']);
            }
        }
    }

    private function hasSupportedPricePrecision(float $price): bool
    {
        if (! is_finite($price)) {
            return false;
        }

        return (float) sprintf(
            '%.'.self::PRICE_SCALE.'F',
            $price,
        ) === $price;
    }

    /**
     * @param  array{
     *   name:?string,
     *   status:?int,
     *   visibility:?int,
     *   price:?float,
     *   mapped_attributes:list<array{attribute_code:string,value:string}>
     * }  $mutation
     */
    private function verifyPostcondition(
        object $postSave,
        string $identifierField,
        string $entityTable,
        object $connection,
        int $logicalEntityId,
        string $expectedSku,
        array $mutation,
    ): ?string {
        if ((int) $postSave->getData($identifierField) !== $logicalEntityId) {
            return 'safe_sync_identity_mismatch_after_save';
        }

        if ((string) $postSave->getSku() !== $expectedSku) {
            return 'safe_sync_postcondition_sku_mismatch';
        }

        if ((string) $postSave->getTypeId() !== 'simple') {
            return 'safe_sync_non_simple_product_type';
        }

        if (! $this->skuResolvesOnlyToLogicalEntity($connection, $entityTable, $identifierField, $logicalEntityId, $expectedSku)) {
            return 'safe_sync_ambiguous_sku';
        }

        if ($mutation['name'] !== null && (string) $postSave->getName() !== $mutation['name']) {
            return 'safe_sync_postcondition_controlled_field_mismatch';
        }

        if ($mutation['status'] !== null && (int) $postSave->getStatus() !== $mutation['status']) {
            return 'safe_sync_postcondition_controlled_field_mismatch';
        }

        if ($mutation['visibility'] !== null && (int) $postSave->getVisibility() !== $mutation['visibility']) {
            return 'safe_sync_postcondition_controlled_field_mismatch';
        }

        if ($mutation['price'] !== null && (float) $postSave->getPrice() !== (float) $mutation['price']) {
            return 'safe_sync_postcondition_controlled_field_mismatch';
        }

        foreach ($mutation['mapped_attributes'] as $attribute) {
            $actualValue = $this->readCustomAttributeValue($postSave, $attribute['attribute_code']);

            if ($actualValue !== $attribute['value']) {
                return 'safe_sync_postcondition_controlled_field_mismatch';
            }
        }

        return null;
    }

    private function readCustomAttributeValue(object $product, string $attributeCode): ?string
    {
        if (method_exists($product, 'getCustomAttribute')) {
            $attribute = $product->getCustomAttribute($attributeCode);

            if (is_object($attribute) && method_exists($attribute, 'getValue')) {
                $value = $attribute->getValue();

                return is_scalar($value) ? (string) $value : null;
            }
        }

        $value = method_exists($product, 'getData') ? $product->getData($attributeCode) : null;

        return is_scalar($value) ? (string) $value : null;
    }

    private function resolveEntityConnection(object $metadata): object
    {
        if (method_exists($metadata, 'getEntityConnection')) {
            $connection = $metadata->getEntityConnection();

            if (is_object($connection)) {
                return $connection;
            }
        }

        if (method_exists($metadata, 'getEntityConnectionName')) {
            return $this->resourceConnection->getConnectionByName((string) $metadata->getEntityConnectionName());
        }

        return $this->resourceConnection->getConnection();
    }

    private function transactionLevel(object $connection): int
    {
        return method_exists($connection, 'getTransactionLevel') ? (int) $connection->getTransactionLevel() : 0;
    }

    private function hasLeadingIndexedColumn(object $connection, string $table, string $column): bool
    {
        $rows = $this->fetchAll(
            $connection,
            sprintf('SHOW INDEX FROM %s', $this->quoteIdentifier($connection, $table)),
        );

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $columnName = strtolower((string) ($row['Column_name'] ?? $row['COLUMN_NAME'] ?? ''));
            $sequence = (int) ($row['Seq_in_index'] ?? $row['SEQ_IN_INDEX'] ?? 0);

            if ($columnName === strtolower($column) && $sequence === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAll(object $connection, string $query): array
    {
        if (! method_exists($connection, 'fetchAll')) {
            return [];
        }

        $rows = $connection->fetchAll($query);

        return is_array($rows) ? array_values($rows) : [];
    }

    private function quoteIdentifier(object $connection, string $identifier): string
    {
        if (method_exists($connection, 'quoteIdentifier')) {
            return (string) $connection->quoteIdentifier($identifier);
        }

        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function quoteString(object $connection, string $value): string
    {
        if (method_exists($connection, 'quote')) {
            return (string) $connection->quote($value);
        }

        return "'".str_replace("'", "\\'", $value)."'";
    }

    private function skuResolvesOnlyToLogicalEntity(
        object $connection,
        string $entityTable,
        string $identifierField,
        int $logicalEntityId,
        string $expectedSku,
    ): bool {
        $rows = $this->fetchAll(
            $connection,
            $this->buildSkuOwnershipLockingQuery($connection, $entityTable, $identifierField, $expectedSku),
        );

        $identifiers = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $value = $row[$identifierField] ?? $row[strtoupper($identifierField)] ?? null;

            if ($value !== null) {
                $identifiers[] = (int) $value;
            }
        }

        $identifiers = array_values(array_unique($identifiers));

        return $identifiers === [$logicalEntityId];
    }

    /**
     * @param  array{previous:?int}  $galeraState
     */
    private function rollbackAndReturn(
        object $connection,
        array $galeraState,
        string $reasonCode,
        int $logicalEntityId,
        string $expectedSku,
        bool $postconditionVerified,
        int $consequentialWriteAttempts,
    ): ProductWriteResponseInterface {
        if ($this->transactionLevel($connection) <= 0) {
            $warningCodes = $this->quarantineConnection($connection);

            return $this->unknownOrAmbiguous(
                'safe_sync_rollback_uncertain',
                $logicalEntityId,
                $expectedSku,
                false,
                $consequentialWriteAttempts,
                $warningCodes,
            );
        }

        try {
            $connection->rollBack();
        } catch (\Throwable $exception) {
            $this->logger->error('Safe Sync rollback acknowledgement is uncertain.', ['exception' => $exception]);
            $warningCodes = $this->quarantineConnection($connection);

            return $this->unknownOrAmbiguous(
                'safe_sync_rollback_uncertain',
                $logicalEntityId,
                $expectedSku,
                false,
                $consequentialWriteAttempts,
                $warningCodes,
            );
        }

        if ($this->transactionLevel($connection) !== 0) {
            $this->logger->error('Safe Sync rollback left the transaction level open.');
            $warningCodes = $this->quarantineConnection($connection);

            return $this->unknownOrAmbiguous(
                'safe_sync_rollback_uncertain',
                $logicalEntityId,
                $expectedSku,
                false,
                $consequentialWriteAttempts,
                $warningCodes,
            );
        }

        $warningCodes = [];

        try {
            $this->callbackBridge->clearPendingProductCallbacks();
        } catch (\Throwable $exception) {
            $warningCodes[] = 'safe_sync_rollback_callback_clear_failed';
            $this->logger->warning('Safe Sync rollback callback clear failed.', ['exception' => $exception]);
        }

        try {
            $this->galeraWriteSession->restore($connection, $galeraState);
        } catch (\Throwable $exception) {
            $warningCodes[] = 'safe_sync_rollback_galera_restore_failed';
            $warningCodes = array_values(array_unique(array_merge(
                $warningCodes,
                $this->warningCodesFromRestoreFailure($exception),
            )));
            $this->logger->warning('Safe Sync rollback Galera restore failed.', ['exception' => $exception]);
        }

        return $this->knownNotApplied(
            $reasonCode,
            $logicalEntityId,
            $expectedSku,
            $postconditionVerified,
            $consequentialWriteAttempts,
            $warningCodes,
        );
    }

    /**
     * @param  array{previous:?int}  $galeraState
     * @return list<string>
     */
    private function restoreAfterCommitException(object $connection, array $galeraState): array
    {
        $warningCodes = [];

        if ($this->transactionLevel($connection) === 0) {
            try {
                $this->callbackBridge->clearPendingProductCallbacks();
            } catch (\Throwable $exception) {
                $warningCodes[] = 'safe_sync_callback_pool_clear_failed';
                $this->logger->warning('Safe Sync uncertain-commit callback clear failed.', ['exception' => $exception]);
            }

            try {
                $this->galeraWriteSession->restore($connection, $galeraState);
            } catch (\Throwable $exception) {
                $warningCodes[] = 'safe_sync_post_commit_galera_restore_failed';
                $warningCodes = array_values(array_unique(array_merge(
                    $warningCodes,
                    $this->warningCodesFromRestoreFailure($exception),
                )));
                $this->logger->error('Safe Sync uncertain-commit Galera restore failed.', ['exception' => $exception]);
            }

            return $warningCodes;
        }

        return $this->quarantineConnection($connection);
    }

    private function buildSkuOwnershipLockingQuery(
        object $connection,
        string $entityTable,
        string $identifierField,
        string $expectedSku,
    ): string {
        return sprintf(
            'SELECT DISTINCT %s FROM %s WHERE %s = %s LIMIT 2 FOR UPDATE',
            $this->quoteIdentifier($connection, $identifierField),
            $this->quoteIdentifier($connection, $entityTable),
            $this->quoteIdentifier($connection, 'sku'),
            $this->quoteString($connection, $expectedSku),
        );
    }

    /**
     * @return list<string>
     */
    private function quarantineConnection(object $connection): array
    {
        $result = $this->connectionQuarantine->quarantine($connection);
        $warningCodes = [];

        if ($result['callback_clear_failed']) {
            $warningCodes[] = 'safe_sync_callback_pool_clear_failed';
        }

        if (! $result['success']) {
            $warningCodes[] = 'safe_sync_connection_quarantine_failed';
            $this->logger->error('Safe Sync connection quarantine failed.');
        }

        return array_values(array_unique($warningCodes));
    }

    /**
     * @return list<string>
     */
    private function warningCodesFromRestoreFailure(\Throwable $exception): array
    {
        $warningCodes = [];

        if (str_starts_with($exception->getMessage(), 'safe_sync_wsrep_connection_quarantine_failed:')) {
            $warningCodes[] = 'safe_sync_connection_quarantine_failed';

            if (str_contains($exception->getMessage(), 'callback_clear_failed')) {
                $warningCodes[] = 'safe_sync_callback_pool_clear_failed';
            }
        }

        return $warningCodes;
    }

    /**
     * @param  array{previous:?int}  $galeraState
     * @param  array{
     *   name:?string,
     *   status:?int,
     *   visibility:?int,
     *   price:?float,
     *   mapped_attributes:list<array{attribute_code:string,value:string}>
     * }  $mutation
     * @return ProductWriteResponseInterface|array{consequential_write_attempts:int,observed_sku:string}
     */
    private function executeRollbackCapablePhase(
        object $connection,
        array $galeraState,
        string $identifierField,
        string $linkField,
        string $entityTable,
        int $logicalEntityId,
        string $expectedSku,
        array $mutation,
    ): ProductWriteResponseInterface|array {
        $consequentialWriteAttempts = 0;

        try {
            $connection->beginTransaction();
        } catch (\Throwable $exception) {
            $this->logger->warning('Safe Sync outer transaction begin failed.', ['exception' => $exception]);
            $warningCodes = $this->quarantineConnection($connection);

            return $this->knownNotApplied(
                'safe_sync_begin_failed',
                $logicalEntityId,
                $expectedSku,
                false,
                0,
                $warningCodes,
            );
        }

        try {
            $lockedRows = $this->fetchAll(
                $connection,
                sprintf(
                    'SELECT %s FROM %s WHERE %s = %d FOR UPDATE',
                    $this->quoteIdentifier($connection, $linkField),
                    $this->quoteIdentifier($connection, $entityTable),
                    $this->quoteIdentifier($connection, $identifierField),
                    $logicalEntityId,
                ),
            );

            if ($lockedRows === []) {
                return $this->rollbackAndReturn(
                    $connection,
                    $galeraState,
                    'safe_sync_entity_missing',
                    $logicalEntityId,
                    $expectedSku,
                    false,
                    0,
                );
            }

            if (! $this->skuResolvesOnlyToLogicalEntity($connection, $entityTable, $identifierField, $logicalEntityId, $expectedSku)) {
                return $this->rollbackAndReturn(
                    $connection,
                    $galeraState,
                    'safe_sync_ambiguous_sku',
                    $logicalEntityId,
                    $expectedSku,
                    false,
                    0,
                );
            }

            try {
                $product = $this->productRepository->getById($logicalEntityId, false, null, true);
            } catch (NoSuchEntityException) {
                return $this->rollbackAndReturn(
                    $connection,
                    $galeraState,
                    'safe_sync_entity_missing',
                    $logicalEntityId,
                    $expectedSku,
                    false,
                    0,
                );
            }

            if ((int) $product->getData($identifierField) !== $logicalEntityId) {
                return $this->rollbackAndReturn(
                    $connection,
                    $galeraState,
                    'safe_sync_identity_mismatch',
                    $logicalEntityId,
                    $expectedSku,
                    false,
                    0,
                );
            }

            if ((string) $product->getSku() !== $expectedSku) {
                return $this->rollbackAndReturn(
                    $connection,
                    $galeraState,
                    'safe_sync_sku_mismatch',
                    $logicalEntityId,
                    $expectedSku,
                    false,
                    0,
                );
            }

            if ((string) $product->getTypeId() !== 'simple') {
                return $this->rollbackAndReturn(
                    $connection,
                    $galeraState,
                    'safe_sync_non_simple_product_type',
                    $logicalEntityId,
                    $expectedSku,
                    false,
                    0,
                );
            }

            if (method_exists($product, 'unsetData')) {
                $product->unsetData('media_gallery');
            }

            $this->applyMutation($product, $mutation);

            if ($product->getData($identifierField) === null) {
                return $this->rollbackAndReturn(
                    $connection,
                    $galeraState,
                    'safe_sync_identifier_missing_before_save',
                    $logicalEntityId,
                    $expectedSku,
                    false,
                    0,
                );
            }

            if ((int) $product->getData($identifierField) !== $logicalEntityId) {
                return $this->rollbackAndReturn(
                    $connection,
                    $galeraState,
                    'safe_sync_identity_mismatch_before_save',
                    $logicalEntityId,
                    $expectedSku,
                    false,
                    0,
                );
            }

            if ((string) $product->getSku() !== $expectedSku) {
                return $this->rollbackAndReturn(
                    $connection,
                    $galeraState,
                    'safe_sync_sku_mismatch_before_save',
                    $logicalEntityId,
                    $expectedSku,
                    false,
                    0,
                );
            }

            try {
                $consequentialWriteAttempts = 1;
                $this->nonMediaProductWriteScope->runForLogicalEntity(
                    $logicalEntityId,
                    fn (): mixed => $this->productRepository->save($product),
                );
            } catch (\Throwable $exception) {
                $this->logger->warning('Safe Sync product save failed before outer commit.', ['exception' => $exception]);

                return $this->rollbackAndReturn(
                    $connection,
                    $galeraState,
                    'safe_sync_repository_save_failed',
                    $logicalEntityId,
                    $expectedSku,
                    false,
                    $consequentialWriteAttempts,
                );
            }

            try {
                $postSave = $this->productRepository->getById($logicalEntityId, false, null, true);
            } catch (NoSuchEntityException) {
                return $this->rollbackAndReturn(
                    $connection,
                    $galeraState,
                    'safe_sync_entity_missing_after_save',
                    $logicalEntityId,
                    $expectedSku,
                    false,
                    $consequentialWriteAttempts,
                );
            }

            $postconditionReason = $this->verifyPostcondition(
                $postSave,
                $identifierField,
                $entityTable,
                $connection,
                $logicalEntityId,
                $expectedSku,
                $mutation,
            );

            if ($postconditionReason !== null) {
                return $this->rollbackAndReturn(
                    $connection,
                    $galeraState,
                    $postconditionReason,
                    $logicalEntityId,
                    $expectedSku,
                    false,
                    $consequentialWriteAttempts,
                );
            }

            try {
                $connection->commit();
            } catch (\Throwable $exception) {
                $warningCodes = $this->restoreAfterCommitException($connection, $galeraState);
                $this->logger->warning('Safe Sync outer commit acknowledgement is uncertain.', ['exception' => $exception]);

                return $this->unknownOrAmbiguous(
                    'safe_sync_commit_uncertain',
                    $logicalEntityId,
                    $expectedSku,
                    true,
                    $consequentialWriteAttempts,
                    $warningCodes,
                );
            }

            return [
                'consequential_write_attempts' => $consequentialWriteAttempts,
                'observed_sku' => (string) $postSave->getSku(),
            ];
        } catch (\Throwable $exception) {
            $this->logger->error('Safe Sync write failed closed before commit.', ['exception' => $exception]);

            return $this->rollbackAndReturn(
                $connection,
                $galeraState,
                'safe_sync_precommit_failure',
                $logicalEntityId,
                $expectedSku,
                false,
                $consequentialWriteAttempts,
            );
        }
    }

    private function knownApplied(
        string $reasonCode,
        int $logicalEntityId,
        string $sku,
        bool $postconditionVerified,
        int $consequentialWriteAttempts,
        array $warningCodes = [],
    ): ProductWriteResponseInterface {
        return $this->buildResponse(
            self::APPLIED_KNOWN_APPLIED,
            $reasonCode,
            $logicalEntityId,
            $sku,
            $postconditionVerified,
            $consequentialWriteAttempts,
            $warningCodes,
        );
    }

    private function knownNotApplied(
        string $reasonCode,
        int $logicalEntityId,
        string $sku,
        bool $postconditionVerified,
        int $consequentialWriteAttempts,
        array $warningCodes = [],
    ): ProductWriteResponseInterface {
        return $this->buildResponse(
            self::APPLIED_KNOWN_NOT_APPLIED,
            $reasonCode,
            $logicalEntityId,
            $sku,
            $postconditionVerified,
            $consequentialWriteAttempts,
            $warningCodes,
        );
    }

    private function unknownOrAmbiguous(
        string $reasonCode,
        int $logicalEntityId,
        string $sku,
        bool $postconditionVerified,
        int $consequentialWriteAttempts,
        array $warningCodes = [],
    ): ProductWriteResponseInterface {
        return $this->buildResponse(
            self::APPLIED_UNKNOWN_OR_AMBIGUOUS,
            $reasonCode,
            $logicalEntityId,
            $sku,
            $postconditionVerified,
            $consequentialWriteAttempts,
            $warningCodes,
        );
    }

    private function buildResponse(
        string $appliedState,
        string $reasonCode,
        int $logicalEntityId,
        string $sku,
        bool $postconditionVerified,
        int $consequentialWriteAttempts,
        array $warningCodes,
    ): ProductWriteResponseInterface {
        $response = $this->responseFactory->create();
        $response->setAppliedState($appliedState);
        $response->setReasonCode($reasonCode);
        $response->setLogicalEntityId($logicalEntityId);
        $response->setSku($sku);
        $response->setPostconditionVerified($postconditionVerified);
        $response->setConsequentialWriteAttempts($consequentialWriteAttempts);
        $response->setWarningCodes($warningCodes);

        return $response;
    }
}
