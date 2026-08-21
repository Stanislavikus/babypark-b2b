<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Api\Data;

interface ProductReadResponseInterface
{
    public function getLogicalEntityId(): int;

    public function setLogicalEntityId(int $logicalEntityId): self;

    public function getSku(): string;

    public function setSku(string $sku): self;

    public function getTypeId(): string;

    public function setTypeId(string $typeId): self;

    public function getName(): string;

    public function setName(string $name): self;
}

interface HandshakeResponseInterface
{
    public function getContractVersion(): string;

    public function setContractVersion(string $contractVersion): self;

    public function getModuleVersion(): string;

    public function setModuleVersion(string $moduleVersion): self;

    public function getSupportedOperationFamilies(): array;

    public function setSupportedOperationFamilies(array $supportedOperationFamilies): self;
}

namespace B2BPlatform\MagentoSafeSync\Api;

use B2BPlatform\MagentoSafeSync\Api\Data\HandshakeResponseInterface;
use B2BPlatform\MagentoSafeSync\Api\Data\ProductReadResponseInterface;

interface ProductReadManagementInterface
{
    public function readProduct(int $logicalEntityId, string $expectedSku): ProductReadResponseInterface;
}

interface HandshakeManagementInterface
{
    public function handshake(): HandshakeResponseInterface;
}

namespace B2BPlatform\MagentoSafeSync\Model\Data;

use B2BPlatform\MagentoSafeSync\Api\Data\HandshakeResponseInterface;
use B2BPlatform\MagentoSafeSync\Api\Data\ProductReadResponseInterface;

final class ProductReadResponse implements ProductReadResponseInterface
{
    private int $logicalEntityId;

    private string $sku = '';

    private string $typeId = '';

    private string $name = '';

    public function getLogicalEntityId(): int
    {
        return $this->logicalEntityId;
    }

    public function setLogicalEntityId(int $logicalEntityId): ProductReadResponseInterface
    {
        $this->logicalEntityId = $logicalEntityId;

        return $this;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): ProductReadResponseInterface
    {
        $this->sku = $sku;

        return $this;
    }

    public function getTypeId(): string
    {
        return $this->typeId;
    }

    public function setTypeId(string $typeId): ProductReadResponseInterface
    {
        $this->typeId = $typeId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): ProductReadResponseInterface
    {
        $this->name = $name;

        return $this;
    }
}

final class ProductReadResponseFactory
{
    public function create(): ProductReadResponseInterface
    {
        return new ProductReadResponse;
    }
}

final class HandshakeResponse implements HandshakeResponseInterface
{
    private string $contractVersion = '';

    private string $moduleVersion = '';

    /** @var list<string> */
    private array $supportedOperationFamilies = [];

    public function getContractVersion(): string
    {
        return $this->contractVersion;
    }

    public function setContractVersion(string $contractVersion): HandshakeResponseInterface
    {
        $this->contractVersion = $contractVersion;

        return $this;
    }

    public function getModuleVersion(): string
    {
        return $this->moduleVersion;
    }

    public function setModuleVersion(string $moduleVersion): HandshakeResponseInterface
    {
        $this->moduleVersion = $moduleVersion;

        return $this;
    }

    public function getSupportedOperationFamilies(): array
    {
        return $this->supportedOperationFamilies;
    }

    public function setSupportedOperationFamilies(array $supportedOperationFamilies): HandshakeResponseInterface
    {
        $this->supportedOperationFamilies = $supportedOperationFamilies;

        return $this;
    }
}

final class HandshakeResponseFactory
{
    public function create(): HandshakeResponseInterface
    {
        return new HandshakeResponse;
    }
}

namespace Magento\Framework\Exception;

class LocalizedException extends \Exception
{
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

class InputException extends LocalizedException {}

class NoSuchEntityException extends LocalizedException {}

namespace Magento\Framework\DB\Adapter;

interface AdapterInterface
{
    public function fetchRow($sql);

    public function query($sql);
}

namespace Magento\Framework\App;

use Magento\Framework\DB\Adapter\AdapterInterface;

final class ResourceConnection
{
    public const DEFAULT_CONNECTION = 'default';

    public int $closeCalls = 0;

    public function __construct(
        private readonly AdapterInterface $connection,
        private readonly ?\Throwable $closeFailure = null,
    ) {}

    public function getConnection(): AdapterInterface
    {
        return $this->connection;
    }

    public function closeConnection($resourceName = self::DEFAULT_CONNECTION): void
    {
        $this->closeCalls++;

        if ($this->closeFailure !== null) {
            throw $this->closeFailure;
        }
    }
}

namespace Magento\Framework\EntityManager;

final class MetadataPool
{
    public function __construct(
        private readonly object $metadata,
    ) {}

    public function getMetadata(string $entityType): object
    {
        return $this->metadata;
    }
}

namespace Magento\Framework\Module;

interface ModuleListInterface
{
    public function getOne($name);
}

namespace Magento\Catalog\Api\Data;

interface ProductInterface {}

namespace Magento\Catalog\Api;

interface ProductRepositoryInterface
{
    public function getById($productId, $editMode = false, $storeId = null, $forceReload = false);
}

namespace Magento\Catalog\Model\ResourceModel\Product;

final class CollectionFactory
{
    public function __construct(
        private readonly object $collection,
    ) {}

    public function create(): object
    {
        return $this->collection;
    }
}

namespace Tests\Unit\MagentoSafeSync;

use B2BPlatform\MagentoSafeSync\Model\GaleraSessionScope;
use B2BPlatform\MagentoSafeSync\Model\HandshakeManagement;
use B2BPlatform\MagentoSafeSync\Model\ProductReadManagement;
use B2BPlatform\MagentoSafeSync\Model\SafeSyncContract;
use B2BPlatform\MagentoSafeSync\Model\SafeSyncReadException;
use B2BPlatform\MagentoSafeSync\Model\Data\HandshakeResponseFactory;
use B2BPlatform\MagentoSafeSync\Model\Data\ProductReadResponseFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/SafeSyncReadException.php';
require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/SafeSyncContract.php';
require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/GaleraSessionScope.php';
require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/ProductReadManagement.php';
require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/HandshakeManagement.php';

final class MagentoSafeSyncModuleLogicTest extends TestCase
{
    #[Test]
    public function exact_sku_precondition_rejects_leading_whitespace_without_normalization(): void
    {
        $management = $this->productReadManagementWithStoredSku('SKU-77');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('safe_sync_sku_mismatch');

        $management->readProduct(77, ' SKU-77 ');
    }

    #[Test]
    public function exact_sku_precondition_rejects_trailing_whitespace_without_normalization(): void
    {
        $management = $this->productReadManagementWithStoredSku('SKU-77');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('safe_sync_sku_mismatch');

        $management->readProduct(77, 'SKU-77 ');
    }

    #[Test]
    public function handshake_fails_closed_when_module_version_is_missing(): void
    {
        $management = new HandshakeManagement(
            new HandshakeResponseFactory,
            new class implements ModuleListInterface
            {
                public function getOne($name)
                {
                    return [];
                }
            },
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('safe_sync_module_version_unavailable');

        $management->handshake();
    }

    #[Test]
    public function handshake_fails_closed_when_module_version_is_sentinel(): void
    {
        $management = new HandshakeManagement(
            new HandshakeResponseFactory,
            new class implements ModuleListInterface
            {
                public function getOne($name)
                {
                    return ['setup_version' => '0.0.0'];
                }
            },
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('safe_sync_module_version_unavailable');

        $management->handshake();
    }

    #[Test]
    public function ordinary_non_galera_target_allows_callback_without_wsrep_mutation(): void
    {
        $connection = new FakeAdapter([
            "SHOW VARIABLES LIKE 'wsrep_provider'" => null,
        ]);
        $resourceConnection = new ResourceConnection($connection);
        $scope = new GaleraSessionScope($resourceConnection);
        $callbackInvoked = false;

        $result = $scope->execute(function () use (&$callbackInvoked): string {
            $callbackInvoked = true;

            return 'ok';
        });

        $this->assertTrue($callbackInvoked);
        $this->assertSame('ok', $result);
        $this->assertSame([], $connection->queries);
        $this->assertSame(0, $resourceConnection->closeCalls);
    }

    #[Test]
    public function active_galera_with_dirty_reads_on_fails_closed_and_never_executes_callback(): void
    {
        $resourceConnection = new ResourceConnection(new FakeAdapter([
            "SHOW VARIABLES LIKE 'wsrep_provider'" => ['Value' => '/usr/lib/libgalera_smm.so'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_on'" => ['Value' => 'ON'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_dirty_reads'" => ['Value' => 'ON'],
        ]));
        $scope = new GaleraSessionScope($resourceConnection);
        $callbackInvoked = false;

        try {
            $scope->execute(function () use (&$callbackInvoked): void {
                $callbackInvoked = true;
            });
            $this->fail('Expected dirty reads to fail closed.');
        } catch (SafeSyncReadException $exception) {
            $this->assertSame('safe_sync_causal_read_unavailable', $exception->getMessage());
            $this->assertFalse($callbackInvoked);
            $this->assertSame(0, $resourceConnection->closeCalls);
        }
    }

    #[Test]
    public function active_galera_with_not_connected_status_fails_closed(): void
    {
        $scope = $this->healthyGaleraScope(new FakeAdapter([
            "SHOW VARIABLES LIKE 'wsrep_provider'" => ['Value' => '/usr/lib/libgalera_smm.so'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_on'" => ['Value' => 'ON'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_dirty_reads'" => ['Value' => 'OFF'],
            "SHOW STATUS LIKE 'wsrep_connected'" => ['Value' => 'OFF'],
        ]));

        $this->expectException(SafeSyncReadException::class);
        $this->expectExceptionMessage('safe_sync_causal_read_unavailable');

        $scope->execute(static fn (): string => 'unreachable');
    }

    #[Test]
    public function active_galera_with_not_ready_status_fails_closed(): void
    {
        $scope = $this->healthyGaleraScope(new FakeAdapter([
            "SHOW VARIABLES LIKE 'wsrep_provider'" => ['Value' => '/usr/lib/libgalera_smm.so'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_on'" => ['Value' => 'ON'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_dirty_reads'" => ['Value' => 'OFF'],
            "SHOW STATUS LIKE 'wsrep_connected'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_ready'" => ['Value' => 'OFF'],
        ]));

        $this->expectException(SafeSyncReadException::class);
        $this->expectExceptionMessage('safe_sync_causal_read_unavailable');

        $scope->execute(static fn (): string => 'unreachable');
    }

    #[Test]
    public function active_galera_with_non_primary_cluster_status_fails_closed(): void
    {
        $scope = $this->healthyGaleraScope(new FakeAdapter([
            "SHOW VARIABLES LIKE 'wsrep_provider'" => ['Value' => '/usr/lib/libgalera_smm.so'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_on'" => ['Value' => 'ON'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_dirty_reads'" => ['Value' => 'OFF'],
            "SHOW STATUS LIKE 'wsrep_connected'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_ready'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_cluster_status'" => ['Value' => 'Non-Primary'],
        ]));
        $callbackInvoked = false;

        try {
            $scope->execute(function () use (&$callbackInvoked): string {
                $callbackInvoked = true;

                return 'unreachable';
            });
            $this->fail('Expected non-primary cluster status to fail closed.');
        } catch (SafeSyncReadException $exception) {
            $this->assertSame('safe_sync_causal_read_unavailable', $exception->getMessage());
            $this->assertFalse($callbackInvoked);
        }
    }

    #[Test]
    public function active_galera_with_invalid_required_state_fails_closed(): void
    {
        $scope = $this->healthyGaleraScope(new FakeAdapter([
            "SHOW VARIABLES LIKE 'wsrep_provider'" => ['Value' => '/usr/lib/libgalera_smm.so'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_on'" => ['Value' => 'ON'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_dirty_reads'" => ['Value' => 'OFF'],
            "SHOW STATUS LIKE 'wsrep_connected'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_ready'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_cluster_status'" => ['Value' => 'Primary'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_sync_wait'" => ['Value' => 'invalid'],
        ]));
        $callbackInvoked = false;

        try {
            $scope->execute(function () use (&$callbackInvoked): void {
                $callbackInvoked = true;
            });
            $this->fail('Expected invalid wsrep state to fail closed.');
        } catch (SafeSyncReadException $exception) {
            $this->assertSame('safe_sync_causal_read_unavailable', $exception->getMessage());
            $this->assertFalse($callbackInvoked);
        }
    }

    #[Test]
    public function active_galera_accepts_zero_wsrep_sync_wait_and_restores_exact_previous_value(): void
    {
        $connection = new FakeAdapter([
            "SHOW VARIABLES LIKE 'wsrep_provider'" => ['Value' => '/usr/lib/libgalera_smm.so'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_on'" => ['Value' => 'ON'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_dirty_reads'" => ['Value' => 'OFF'],
            "SHOW STATUS LIKE 'wsrep_connected'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_ready'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_cluster_status'" => ['Value' => 'Primary'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_sync_wait'" => ['Value' => '0'],
        ]);
        $resourceConnection = new ResourceConnection($connection);
        $scope = new GaleraSessionScope($resourceConnection);

        $result = $scope->execute(static fn (): string => 'verified');

        $this->assertSame('verified', $result);
        $this->assertSame([
            'SET SESSION wsrep_sync_wait = 1',
            'SET SESSION wsrep_sync_wait = 0',
        ], $connection->queries);
        $this->assertSame(0, $resourceConnection->closeCalls);
    }

    #[Test]
    public function active_galera_accepts_upper_bound_wsrep_sync_wait_of_fifteen(): void
    {
        $connection = new FakeAdapter([
            "SHOW VARIABLES LIKE 'wsrep_provider'" => ['Value' => '/usr/lib/libgalera_smm.so'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_on'" => ['Value' => 'ON'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_dirty_reads'" => ['Value' => 'OFF'],
            "SHOW STATUS LIKE 'wsrep_connected'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_ready'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_cluster_status'" => ['Value' => 'Primary'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_sync_wait'" => ['Value' => '15'],
        ]);
        $scope = $this->healthyGaleraScope($connection);

        $result = $scope->execute(static fn (): string => 'verified');

        $this->assertSame('verified', $result);
        $this->assertSame([
            'SET SESSION wsrep_sync_wait = 15',
            'SET SESSION wsrep_sync_wait = 15',
        ], $connection->queries);
    }

    #[Test]
    public function active_galera_rejects_out_of_range_wsrep_sync_wait(): void
    {
        $this->assertInvalidWsrepSyncWaitValueFailsClosed('16');
    }

    #[Test]
    public function active_galera_rejects_fractional_wsrep_sync_wait(): void
    {
        $this->assertInvalidWsrepSyncWaitValueFailsClosed('1.5');
    }

    #[Test]
    public function active_galera_rejects_negative_wsrep_sync_wait(): void
    {
        $this->assertInvalidWsrepSyncWaitValueFailsClosed('-1');
    }

    #[Test]
    public function wsrep_probe_exception_fails_closed_and_preserves_original_cause(): void
    {
        $probeFailure = new \RuntimeException('forced probe failure');
        $scope = $this->healthyGaleraScope(new FakeAdapter([
            "SHOW VARIABLES LIKE 'wsrep_provider'" => ['Value' => '/usr/lib/libgalera_smm.so'],
        ], fetchFailures: [
            "SHOW SESSION VARIABLES LIKE 'wsrep_on'" => $probeFailure,
        ]));
        $callbackInvoked = false;

        try {
            $scope->execute(function () use (&$callbackInvoked): string {
                $callbackInvoked = true;

                return 'unreachable';
            });
            $this->fail('Expected wsrep probe failure to fail closed.');
        } catch (SafeSyncReadException $exception) {
            $this->assertFalse($callbackInvoked);
            $this->assertSame('safe_sync_causal_read_unavailable', $exception->getMessage());
            $this->assertSame($probeFailure, $exception->getPrevious());
        }
    }

    #[Test]
    public function active_healthy_galera_adds_read_bit_preserves_existing_bits_and_restores_exact_previous_value(): void
    {
        $connection = new FakeAdapter([
            "SHOW VARIABLES LIKE 'wsrep_provider'" => ['Value' => '/usr/lib/libgalera_smm.so'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_on'" => ['Value' => 'ON'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_dirty_reads'" => ['Value' => 'OFF'],
            "SHOW STATUS LIKE 'wsrep_connected'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_ready'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_cluster_status'" => ['Value' => 'Primary'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_sync_wait'" => ['Value' => '6'],
        ]);
        $scope = $this->healthyGaleraScope($connection);

        $result = $scope->execute(static fn (): string => 'verified');

        $this->assertSame('verified', $result);
        $this->assertSame([
            'SET SESSION wsrep_sync_wait = 7',
            'SET SESSION wsrep_sync_wait = 6',
        ], $connection->queries);
    }

    #[Test]
    public function restoration_failure_prevents_successful_result_from_escaping(): void
    {
        $restoreFailure = new \RuntimeException('restore failed');
        $connection = new FakeAdapter([
            "SHOW VARIABLES LIKE 'wsrep_provider'" => ['Value' => '/usr/lib/libgalera_smm.so'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_on'" => ['Value' => 'ON'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_dirty_reads'" => ['Value' => 'OFF'],
            "SHOW STATUS LIKE 'wsrep_connected'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_ready'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_cluster_status'" => ['Value' => 'Primary'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_sync_wait'" => ['Value' => '2'],
        ], [
            'SET SESSION wsrep_sync_wait = 2' => $restoreFailure,
        ]);
        $resourceConnection = new ResourceConnection($connection);
        $scope = new GaleraSessionScope($resourceConnection);
        $callbackInvoked = false;

        try {
            $scope->execute(function () use (&$callbackInvoked): string {
                $callbackInvoked = true;

                return 'verified';
            });
            $this->fail('Expected restore failure to prevent success from escaping.');
        } catch (SafeSyncReadException $exception) {
            $this->assertTrue($callbackInvoked);
            $this->assertSame('safe_sync_wsrep_restore_failed', $exception->getMessage());
            $this->assertSame($restoreFailure, $exception->getPrevious());
            $this->assertSame(1, $resourceConnection->closeCalls);
        }
    }

    #[Test]
    public function quarantine_failure_remains_fail_closed_and_diagnosable(): void
    {
        $restoreFailure = new \RuntimeException('restore failed');
        $quarantineFailure = new \RuntimeException('close failed');
        $connection = new FakeAdapter([
            "SHOW VARIABLES LIKE 'wsrep_provider'" => ['Value' => '/usr/lib/libgalera_smm.so'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_on'" => ['Value' => 'ON'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_dirty_reads'" => ['Value' => 'OFF'],
            "SHOW STATUS LIKE 'wsrep_connected'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_ready'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_cluster_status'" => ['Value' => 'Primary'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_sync_wait'" => ['Value' => '4'],
        ], [
            'SET SESSION wsrep_sync_wait = 4' => $restoreFailure,
        ]);
        $resourceConnection = new ResourceConnection($connection, $quarantineFailure);
        $scope = new GaleraSessionScope($resourceConnection);
        $callbackInvoked = false;

        try {
            $scope->execute(function () use (&$callbackInvoked): string {
                $callbackInvoked = true;

                return 'verified';
            });
            $this->fail('Expected quarantine failure to remain fail closed.');
        } catch (SafeSyncReadException $exception) {
            $this->assertTrue($callbackInvoked);
            $this->assertSame('safe_sync_wsrep_restore_failed', $exception->getMessage());
            $this->assertSame(1, $resourceConnection->closeCalls);
            $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
            $this->assertSame(
                'safe_sync_wsrep_connection_quarantine_failed:RuntimeException',
                $exception->getPrevious()?->getMessage(),
            );
            $this->assertSame($restoreFailure, $exception->getPrevious()?->getPrevious());
        }
    }

    private function productReadManagementWithStoredSku(string $storedSku): ProductReadManagement
    {
        $product = new FakeProduct(
            entityId: 77,
            sku: $storedSku,
            typeId: 'simple',
            name: 'Verified Product',
        );

        $repository = new class($product) implements ProductRepositoryInterface
        {
            public function __construct(
                private readonly FakeProduct $product,
            ) {}

            public function getById($productId, $editMode = false, $storeId = null, $forceReload = false)
            {
                return $this->product;
            }
        };

        $metadata = new class
        {
            public function getIdentifierField(): string
            {
                return 'entity_id';
            }
        };

        $collection = new FakeProductCollection([]);
        $galeraScope = new GaleraSessionScope(new ResourceConnection(new FakeAdapter([
            "SHOW VARIABLES LIKE 'wsrep_provider'" => null,
        ])));

        return new ProductReadManagement(
            $repository,
            new MetadataPool($metadata),
            new CollectionFactory($collection),
            new ProductReadResponseFactory,
            $galeraScope,
        );
    }

    private function healthyGaleraScope(FakeAdapter $connection): GaleraSessionScope
    {
        return new GaleraSessionScope(new ResourceConnection($connection));
    }

    private function assertInvalidWsrepSyncWaitValueFailsClosed(string $value): void
    {
        $scope = $this->healthyGaleraScope(new FakeAdapter([
            "SHOW VARIABLES LIKE 'wsrep_provider'" => ['Value' => '/usr/lib/libgalera_smm.so'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_on'" => ['Value' => 'ON'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_dirty_reads'" => ['Value' => 'OFF'],
            "SHOW STATUS LIKE 'wsrep_connected'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_ready'" => ['Value' => 'ON'],
            "SHOW STATUS LIKE 'wsrep_cluster_status'" => ['Value' => 'Primary'],
            "SHOW SESSION VARIABLES LIKE 'wsrep_sync_wait'" => ['Value' => $value],
        ]));
        $callbackInvoked = false;

        try {
            $scope->execute(function () use (&$callbackInvoked): string {
                $callbackInvoked = true;

                return 'unreachable';
            });
            $this->fail('Expected invalid wsrep_sync_wait value to fail closed.');
        } catch (SafeSyncReadException $exception) {
            $this->assertSame('safe_sync_causal_read_unavailable', $exception->getMessage());
            $this->assertFalse($callbackInvoked);
        }
    }
}

final class FakeAdapter implements AdapterInterface
{
    /** @var array<string, mixed> */
    private array $rows;

    /** @var array<string, \Throwable> */
    private array $queryFailures;

    /** @var array<string, \Throwable> */
    private array $fetchFailures;

    /** @var list<string> */
    public array $queries = [];

    /**
     * @param array<string, mixed> $rows
     * @param array<string, \Throwable> $queryFailures
     * @param array<string, \Throwable> $fetchFailures
     */
    public function __construct(array $rows, array $queryFailures = [], array $fetchFailures = [])
    {
        $this->rows = $rows;
        $this->queryFailures = $queryFailures;
        $this->fetchFailures = $fetchFailures;
    }

    public function fetchRow($sql)
    {
        if (isset($this->fetchFailures[$sql])) {
            throw $this->fetchFailures[$sql];
        }

        return $this->rows[$sql] ?? null;
    }

    public function query($sql)
    {
        $this->queries[] = $sql;

        if (isset($this->queryFailures[$sql])) {
            throw $this->queryFailures[$sql];
        }

        return null;
    }
}

final class FakeProduct implements ProductInterface
{
    public function __construct(
        private readonly int $entityId,
        private readonly string $sku,
        private readonly string $typeId,
        private readonly string $name,
    ) {}

    public function getData(string $key): mixed
    {
        return match ($key) {
            'entity_id' => $this->entityId,
            default => null,
        };
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getTypeId(): string
    {
        return $this->typeId;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

final class FakeProductCollection implements \IteratorAggregate
{
    /** @var list<FakeProduct> */
    private array $products;

    /** @var list<FakeProduct> */
    private array $filteredProducts;

    /**
     * @param list<FakeProduct> $products
     */
    public function __construct(array $products)
    {
        $this->products = $products;
        $this->filteredProducts = $products;
    }

    public function addAttributeToSelect(array $attributes): self
    {
        return $this;
    }

    public function addAttributeToFilter(string $attribute, array $condition): self
    {
        $expected = $condition['eq'] ?? null;

        $this->filteredProducts = array_values(array_filter(
            $this->products,
            static fn (FakeProduct $product): bool => $attribute !== 'sku' || $product->getSku() === $expected,
        ));

        return $this;
    }

    public function setPageSize(int $pageSize): self
    {
        $this->filteredProducts = array_slice($this->filteredProducts, 0, $pageSize);

        return $this;
    }

    public function setCurPage(int $currentPage): self
    {
        return $this;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->filteredProducts);
    }
}
