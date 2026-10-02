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

    public function getApplicationVersion(): ?string;

    public function setApplicationVersion(?string $applicationVersion): self;

    public function getPhpVersion(): ?string;

    public function setPhpVersion(?string $phpVersion): self;
}

interface ProductWriteRequestInterface
{
    public function getExpectedSku(): string;

    public function setExpectedSku(string $expectedSku): self;

    public function getName(): ?string;

    public function setName(?string $name): self;

    public function getStatus(): ?int;

    public function setStatus(?int $status): self;

    public function getVisibility(): ?int;

    public function setVisibility(?int $visibility): self;

    public function getPrice(): ?float;

    public function setPrice(?float $price): self;

    public function getMappedAttributes(): array;

    public function setMappedAttributes(array $mappedAttributes): self;
}

interface ProductWriteMappedAttributeInterface
{
    public function getAttributeCode(): string;

    public function setAttributeCode(string $attributeCode): self;

    public function getValue(): string;

    public function setValue(string $value): self;
}

interface ProductWriteResponseInterface
{
    public function getAppliedState(): string;

    public function setAppliedState(string $appliedState): self;

    public function getReasonCode(): string;

    public function setReasonCode(string $reasonCode): self;

    public function getLogicalEntityId(): int;

    public function setLogicalEntityId(int $logicalEntityId): self;

    public function getSku(): string;

    public function setSku(string $sku): self;

    public function getPostconditionVerified(): bool;

    public function setPostconditionVerified(bool $postconditionVerified): self;

    public function getConsequentialWriteAttempts(): int;

    public function setConsequentialWriteAttempts(int $consequentialWriteAttempts): self;

    public function getWarningCodes(): array;

    public function setWarningCodes(array $warningCodes): self;
}

namespace B2BPlatform\MagentoSafeSync\Api;

use B2BPlatform\MagentoSafeSync\Api\Data\HandshakeResponseInterface;
use B2BPlatform\MagentoSafeSync\Api\Data\ProductReadResponseInterface;
use B2BPlatform\MagentoSafeSync\Api\Data\ProductWriteRequestInterface;
use B2BPlatform\MagentoSafeSync\Api\Data\ProductWriteResponseInterface;

interface ProductReadManagementInterface
{
    public function readProduct(int $logicalEntityId, string $expectedSku): ProductReadResponseInterface;
}

interface HandshakeManagementInterface
{
    public function handshake(): HandshakeResponseInterface;
}

interface ProductWriteManagementInterface
{
    public function writeSimpleProduct(
        int $logicalEntityId,
        ProductWriteRequestInterface $request,
    ): ProductWriteResponseInterface;
}

namespace B2BPlatform\MagentoSafeSync\Model\Data;

use B2BPlatform\MagentoSafeSync\Api\Data\HandshakeResponseInterface;
use B2BPlatform\MagentoSafeSync\Api\Data\ProductReadResponseInterface;
use B2BPlatform\MagentoSafeSync\Api\Data\ProductWriteMappedAttributeInterface;
use B2BPlatform\MagentoSafeSync\Api\Data\ProductWriteRequestInterface;
use B2BPlatform\MagentoSafeSync\Api\Data\ProductWriteResponseInterface;

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

    private ?string $applicationVersion = null;

    private ?string $phpVersion = null;

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

    public function getApplicationVersion(): ?string
    {
        return $this->applicationVersion;
    }

    public function setApplicationVersion(?string $applicationVersion): HandshakeResponseInterface
    {
        $this->applicationVersion = $applicationVersion;

        return $this;
    }

    public function getPhpVersion(): ?string
    {
        return $this->phpVersion;
    }

    public function setPhpVersion(?string $phpVersion): HandshakeResponseInterface
    {
        $this->phpVersion = $phpVersion;

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

final class ProductWriteRequest implements ProductWriteRequestInterface
{
    private string $expectedSku = '';

    private ?string $name = null;

    private ?int $status = null;

    private ?int $visibility = null;

    private ?float $price = null;

    /** @var list<ProductWriteMappedAttributeInterface> */
    private array $mappedAttributes = [];

    public function getExpectedSku(): string
    {
        return $this->expectedSku;
    }

    public function setExpectedSku(string $expectedSku): ProductWriteRequestInterface
    {
        $this->expectedSku = $expectedSku;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): ProductWriteRequestInterface
    {
        $this->name = $name;

        return $this;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function setStatus(?int $status): ProductWriteRequestInterface
    {
        $this->status = $status;

        return $this;
    }

    public function getVisibility(): ?int
    {
        return $this->visibility;
    }

    public function setVisibility(?int $visibility): ProductWriteRequestInterface
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(?float $price): ProductWriteRequestInterface
    {
        $this->price = $price;

        return $this;
    }

    public function getMappedAttributes(): array
    {
        return $this->mappedAttributes;
    }

    public function setMappedAttributes(array $mappedAttributes): ProductWriteRequestInterface
    {
        $this->mappedAttributes = array_values($mappedAttributes);

        return $this;
    }
}

final class ProductWriteMappedAttribute implements ProductWriteMappedAttributeInterface
{
    private string $attributeCode = '';

    private string $value = '';

    public function getAttributeCode(): string
    {
        return $this->attributeCode;
    }

    public function setAttributeCode(string $attributeCode): ProductWriteMappedAttributeInterface
    {
        $this->attributeCode = $attributeCode;

        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): ProductWriteMappedAttributeInterface
    {
        $this->value = $value;

        return $this;
    }
}

final class ProductWriteResponse implements ProductWriteResponseInterface
{
    private string $appliedState = '';

    private string $reasonCode = '';

    private int $logicalEntityId = 0;

    private string $sku = '';

    private bool $postconditionVerified = false;

    private int $consequentialWriteAttempts = 0;

    /** @var list<string> */
    private array $warningCodes = [];

    public function getAppliedState(): string
    {
        return $this->appliedState;
    }

    public function setAppliedState(string $appliedState): ProductWriteResponseInterface
    {
        $this->appliedState = $appliedState;

        return $this;
    }

    public function getReasonCode(): string
    {
        return $this->reasonCode;
    }

    public function setReasonCode(string $reasonCode): ProductWriteResponseInterface
    {
        $this->reasonCode = $reasonCode;

        return $this;
    }

    public function getLogicalEntityId(): int
    {
        return $this->logicalEntityId;
    }

    public function setLogicalEntityId(int $logicalEntityId): ProductWriteResponseInterface
    {
        $this->logicalEntityId = $logicalEntityId;

        return $this;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): ProductWriteResponseInterface
    {
        $this->sku = $sku;

        return $this;
    }

    public function getPostconditionVerified(): bool
    {
        return $this->postconditionVerified;
    }

    public function setPostconditionVerified(bool $postconditionVerified): ProductWriteResponseInterface
    {
        $this->postconditionVerified = $postconditionVerified;

        return $this;
    }

    public function getConsequentialWriteAttempts(): int
    {
        return $this->consequentialWriteAttempts;
    }

    public function setConsequentialWriteAttempts(int $consequentialWriteAttempts): ProductWriteResponseInterface
    {
        $this->consequentialWriteAttempts = $consequentialWriteAttempts;

        return $this;
    }

    public function getWarningCodes(): array
    {
        return $this->warningCodes;
    }

    public function setWarningCodes(array $warningCodes): ProductWriteResponseInterface
    {
        $this->warningCodes = array_values($warningCodes);

        return $this;
    }
}

final class ProductWriteResponseFactory
{
    public function create(): ProductWriteResponseInterface
    {
        return new ProductWriteResponse;
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

    public function closeConnection();
}

namespace Magento\Framework\ObjectManager;

interface ResetAfterRequestInterface
{
    public function _resetState(): void;
}

namespace Magento\Framework\Model;

final class CallbackPool
{
    /** @var list<string> */
    public static array $clearedHashes = [];

    /** @var list<string> */
    public static array $events = [];

    /** @var array<string, \Throwable> */
    public static array $failures = [];

    public static function clear(string $connectionHash): void
    {
        self::$events[] = 'clear:'.$connectionHash;
        self::$clearedHashes[] = $connectionHash;

        if (isset(self::$failures[$connectionHash])) {
            throw self::$failures[$connectionHash];
        }
    }

    public static function reset(): void
    {
        self::$clearedHashes = [];
        self::$events = [];
        self::$failures = [];
    }
}

namespace Magento\Framework\App;

use Magento\Framework\DB\Adapter\AdapterInterface;

final class ResourceConnection
{
    public const DEFAULT_CONNECTION = 'default';

    public int $closeCalls = 0;

    /** @var array<string, AdapterInterface> */
    private array $connections;

    public function __construct(
        AdapterInterface $connection,
        private readonly ?\Throwable $closeFailure = null,
        array $namedConnections = [],
    ) {
        $this->connections = $namedConnections + [
            self::DEFAULT_CONNECTION => $connection,
        ];
    }

    public function getConnection($resourceName = self::DEFAULT_CONNECTION): AdapterInterface
    {
        return $this->connections[$resourceName] ?? $this->connections[self::DEFAULT_CONNECTION];
    }

    public function getConnectionByName(?string $connectionName): AdapterInterface
    {
        return $this->connections[$connectionName ?? self::DEFAULT_CONNECTION] ?? $this->connections[self::DEFAULT_CONNECTION];
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

final class CallbackHandler
{
    public int $clearCalls = 0;

    public int $processCalls = 0;

    public ?\Throwable $clearFailure = null;

    public ?\Throwable $processFailure = null;

    public function process($entityType)
    {
        $this->processCalls++;

        if ($this->processFailure !== null) {
            throw $this->processFailure;
        }
    }

    public function clear($entityType)
    {
        $this->clearCalls++;

        if ($this->clearFailure !== null) {
            throw $this->clearFailure;
        }
    }
}

namespace Psr\Log;

interface LoggerInterface
{
    public function emergency(string|\Stringable $message, array $context = []): void;

    public function alert(string|\Stringable $message, array $context = []): void;

    public function critical(string|\Stringable $message, array $context = []): void;

    public function error(string|\Stringable $message, array $context = []): void;

    public function warning(string|\Stringable $message, array $context = []): void;

    public function notice(string|\Stringable $message, array $context = []): void;

    public function info(string|\Stringable $message, array $context = []): void;

    public function debug(string|\Stringable $message, array $context = []): void;

    public function log($level, string|\Stringable $message, array $context = []): void;
}

namespace Magento\Framework\Module;

interface ModuleListInterface
{
    public function getOne($name);
}

namespace Magento\Framework\App;

interface ProductMetadataInterface
{
    public function getVersion(): string;
}

namespace Magento\Catalog\Api\Data;

interface ProductInterface {}

namespace Magento\Catalog\Api;

interface ProductRepositoryInterface
{
    public function getById($productId, $editMode = false, $storeId = null, $forceReload = false);

    public function save($product, $saveOptions = false);
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

namespace Magento\Catalog\Model\Product\Media;

final class Config
{
    /**
     * @param  list<string>|\Throwable  $mediaAttributeCodes
     */
    public function __construct(
        private readonly array|\Throwable $mediaAttributeCodes = ['image', 'small_image', 'thumbnail'],
    ) {}

    /**
     * @return list<string>
     */
    public function getMediaAttributeCodes(): array
    {
        if ($this->mediaAttributeCodes instanceof \Throwable) {
            throw $this->mediaAttributeCodes;
        }

        return $this->mediaAttributeCodes;
    }
}

namespace Magento\Catalog\Model\Product\Gallery;

class UpdateHandler
{
    public function execute(object $product, array $arguments = []): object
    {
        return $product;
    }
}

namespace Tests\Unit\MagentoSafeSync;

use B2BPlatform\MagentoSafeSync\Model\Connection\ConnectionQuarantine;
use B2BPlatform\MagentoSafeSync\Model\Data\HandshakeResponseFactory;
use B2BPlatform\MagentoSafeSync\Model\Data\ProductReadResponseFactory;
use B2BPlatform\MagentoSafeSync\Model\Data\ProductWriteMappedAttribute;
use B2BPlatform\MagentoSafeSync\Model\Data\ProductWriteRequest;
use B2BPlatform\MagentoSafeSync\Model\Data\ProductWriteResponseFactory;
use B2BPlatform\MagentoSafeSync\Model\GaleraSessionScope;
use B2BPlatform\MagentoSafeSync\Model\GaleraWriteSession;
use B2BPlatform\MagentoSafeSync\Model\HandshakeManagement;
use B2BPlatform\MagentoSafeSync\Model\Media\NonMediaProductWriteScope;
use B2BPlatform\MagentoSafeSync\Model\ProductEntityManagerCallbackBridge;
use B2BPlatform\MagentoSafeSync\Model\ProductReadManagement;
use B2BPlatform\MagentoSafeSync\Model\ProductWriteManagement;
use B2BPlatform\MagentoSafeSync\Model\SafeSyncReadException;
use B2BPlatform\MagentoSafeSync\Plugin\Gallery\UpdateHandlerNonMediaBypassPlugin;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Gallery\UpdateHandler;
use Magento\Catalog\Model\Product\Media\Config as ProductMediaConfig;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\CallbackHandler;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\CallbackPool;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/SafeSyncReadException.php';
require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/SafeSyncContract.php';
require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/GaleraSessionScope.php';
require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/GaleraWriteSession.php';
require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/ProductReadManagement.php';
require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/ProductEntityManagerCallbackBridge.php';
require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/Connection/ConnectionQuarantine.php';
require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/Media/NonMediaProductWriteScope.php';
require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/ProductWriteManagement.php';
require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Model/HandshakeManagement.php';
require_once dirname(__DIR__, 3).'/integrations/magento-safe-sync/Plugin/Gallery/UpdateHandlerNonMediaBypassPlugin.php';

final class MagentoSafeSyncModuleLogicTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CallbackPool::reset();
    }

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
            new class implements ProductMetadataInterface
            {
                public function getVersion(): string
                {
                    return '2.4.0';
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
            new class implements ProductMetadataInterface
            {
                public function getVersion(): string
                {
                    return '2.4.0';
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

    #[Test]
    public function handshake_advertises_optional_simple_write_family_without_contract_bump(): void
    {
        $management = new HandshakeManagement(
            new HandshakeResponseFactory,
            new class implements ModuleListInterface
            {
                public function getOne($name)
                {
                    return ['setup_version' => '0.2.1'];
                }
            },
            new class implements ProductMetadataInterface
            {
                public function getVersion(): string
                {
                    return '2.4.7-p1';
                }
            },
        );

        $handshake = $management->handshake();

        $this->assertSame('stage3e-r1', $handshake->getContractVersion());
        $this->assertSame('0.2.1', $handshake->getModuleVersion());
        $this->assertSame([
            'entity_bound_product_read',
            'entity_bound_simple_product_write',
        ], $handshake->getSupportedOperationFamilies());
        $this->assertSame('2.4.7-p1', $handshake->getApplicationVersion());
        $this->assertSame(phpversion(), $handshake->getPhpVersion());
    }

    #[Test]
    public function non_media_scope_closes_in_finally_and_cannot_leak_after_exception(): void
    {
        $scope = new NonMediaProductWriteScope;

        try {
            $scope->runForLogicalEntity(77, function () use ($scope): void {
                $this->assertTrue($scope->isActiveForLogicalEntity(77));
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected the callback exception to escape.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertFalse($scope->isActiveForLogicalEntity(77));
    }

    #[Test]
    public function non_media_scope_reentry_fails_closed(): void
    {
        $scope = new NonMediaProductWriteScope;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('safe_sync_non_media_scope_reentry_forbidden');

        $scope->runForLogicalEntity(77, fn (): mixed => $scope->runForLogicalEntity(77, static fn (): null => null));
    }

    #[Test]
    public function gallery_update_handler_plugin_suppresses_only_the_exact_logical_entity_in_scope(): void
    {
        $scope = new NonMediaProductWriteScope;
        $metadata = new class
        {
            public function getIdentifierField(): string
            {
                return 'entity_id';
            }
        };
        $plugin = new UpdateHandlerNonMediaBypassPlugin($scope, new MetadataPool($metadata));
        $subject = new UpdateHandler;
        $target = new FakeProduct(entityId: 77, sku: 'SKU-77', typeId: 'simple', name: 'Target');
        $other = new FakeProduct(entityId: 88, sku: 'SKU-88', typeId: 'simple', name: 'Other');
        $proceedCalls = 0;

        $inactive = $plugin->aroundExecute($subject, function (object $product, array $arguments) use (&$proceedCalls): object {
            $proceedCalls++;

            return $product;
        }, $target, []);

        $this->assertSame($target, $inactive);
        $this->assertSame(1, $proceedCalls);

        $scope->runForLogicalEntity(77, function () use ($plugin, $subject, $target, $other, &$proceedCalls): void {
            $suppressed = $plugin->aroundExecute($subject, function (object $product, array $arguments) use (&$proceedCalls): object {
                $proceedCalls++;

                return $product;
            }, $target, []);

            $passedThrough = $plugin->aroundExecute($subject, function (object $product, array $arguments) use (&$proceedCalls): object {
                $proceedCalls++;

                return $product;
            }, $other, []);

            $this->assertSame($target, $suppressed);
            $this->assertSame($other, $passedThrough);
        });

        $this->assertSame(2, $proceedCalls);
    }

    #[Test]
    public function missing_entity_returns_known_not_applied_without_write_attempt(): void
    {
        $fixture = $this->simpleWriteFixture(lockRows: [], skuRows: [['entity_id' => 77]]);

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated'));

        $this->assertSame('known_not_applied', $result->getAppliedState());
        $this->assertSame('safe_sync_entity_missing', $result->getReasonCode());
        $this->assertSame(0, $result->getConsequentialWriteAttempts());
        $this->assertSame(1, $fixture['callbackHandler']->clearCalls);
        $this->assertSame(['begin', 'rollback'], $fixture['connection']->txEvents);
    }

    #[Test]
    public function exact_sku_mismatch_returns_known_not_applied_without_save_attempt(): void
    {
        $fixture = $this->simpleWriteFixture(product: new FakeProduct(entityId: 77, sku: 'OTHER', typeId: 'simple', name: 'Name'));

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated'));

        $this->assertSame('known_not_applied', $result->getAppliedState());
        $this->assertSame('safe_sync_sku_mismatch', $result->getReasonCode());
        $this->assertSame(0, $result->getConsequentialWriteAttempts());
        $this->assertSame(0, $fixture['repository']->saveCalls);
    }

    #[Test]
    public function ambiguous_sku_returns_known_not_applied_without_write_attempt(): void
    {
        $fixture = $this->simpleWriteFixture(skuRows: [['entity_id' => 77], ['entity_id' => 88]]);

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated'));

        $this->assertSame('safe_sync_ambiguous_sku', $result->getReasonCode());
        $this->assertSame(0, $result->getConsequentialWriteAttempts());
    }

    #[Test]
    public function malformed_mapped_attributes_fail_closed_without_consequential_write_attempt(): void
    {
        $request = $this->writeRequest('SKU-77', name: 'Updated');
        $request->setMappedAttributes([['attribute_code' => 'color', 'value' => 'red']]);
        $fixture = $this->simpleWriteFixture();

        $result = $fixture['management']->writeSimpleProduct(77, $request);

        $this->assertSame('known_not_applied', $result->getAppliedState());
        $this->assertSame('safe_sync_invalid_mapped_attribute_payload', $result->getReasonCode());
        $this->assertSame(0, $result->getConsequentialWriteAttempts());
    }

    #[Test]
    public function mapped_media_attribute_codes_fail_closed_before_write_attempt(): void
    {
        $fixture = $this->simpleWriteFixture(mediaAttributeCodes: ['image', 'custom_media']);

        $result = $fixture['management']->writeSimpleProduct(
            77,
            $this->writeRequest('SKU-77', mappedAttributes: [
                $this->mappedAttribute('custom_media', 'file.jpg'),
            ]),
        );

        $this->assertSame('known_not_applied', $result->getAppliedState());
        $this->assertSame('safe_sync_media_attribute_not_allowed', $result->getReasonCode());
        $this->assertSame(0, $result->getConsequentialWriteAttempts());
        $this->assertSame(0, $fixture['repository']->saveCalls);
    }

    #[Test]
    public function mapped_media_label_attribute_codes_also_fail_closed_before_write_attempt(): void
    {
        $fixture = $this->simpleWriteFixture(mediaAttributeCodes: ['image', 'custom_media']);

        $result = $fixture['management']->writeSimpleProduct(
            77,
            $this->writeRequest('SKU-77', mappedAttributes: [
                $this->mappedAttribute('custom_media_label', 'Front'),
            ]),
        );

        $this->assertSame('safe_sync_media_attribute_not_allowed', $result->getReasonCode());
        $this->assertSame(0, $result->getConsequentialWriteAttempts());
        $this->assertSame(0, $fixture['repository']->saveCalls);
    }

    #[Test]
    public function unavailable_media_attribute_capability_fails_closed_before_write_attempt(): void
    {
        $fixture = $this->simpleWriteFixture(mediaConfigFailure: new \RuntimeException('unsupported'));

        $result = $fixture['management']->writeSimpleProduct(
            77,
            $this->writeRequest('SKU-77', mappedAttributes: [
                $this->mappedAttribute('color', 'red'),
            ]),
        );

        $this->assertSame('safe_sync_media_attribute_capability_unavailable', $result->getReasonCode());
        $this->assertSame(0, $result->getConsequentialWriteAttempts());
        $this->assertSame(0, $fixture['repository']->saveCalls);
    }

    #[Test]
    public function non_simple_type_returns_known_not_applied_without_save_attempt(): void
    {
        $fixture = $this->simpleWriteFixture(product: new FakeProduct(entityId: 77, sku: 'SKU-77', typeId: 'configurable', name: 'Name'));

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated'));

        $this->assertSame('safe_sync_non_simple_product_type', $result->getReasonCode());
        $this->assertSame(0, $fixture['repository']->saveCalls);
    }

    #[Test]
    public function missing_identifier_index_fails_closed_before_transaction(): void
    {
        $fixture = $this->simpleWriteFixture(indexRows: [
            ['Column_name' => 'sku', 'Seq_in_index' => 1],
        ]);

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated'));

        $this->assertSame('safe_sync_identifier_index_unavailable', $result->getReasonCode());
        $this->assertSame([], $fixture['connection']->txEvents);
    }

    #[Test]
    public function missing_sku_index_fails_closed_before_transaction(): void
    {
        $fixture = $this->simpleWriteFixture(indexRows: [
            ['Column_name' => 'entity_id', 'Seq_in_index' => 1],
        ]);

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated'));

        $this->assertSame('safe_sync_sku_index_unavailable', $result->getReasonCode());
        $this->assertSame([], $fixture['connection']->txEvents);
    }

    /**
     * @return list<array{0:float}>
     */
    public static function acceptedPriceProvider(): array
    {
        return [
            [0.1],
            [12.34],
            [64.01],
            [199.0],
            [199.99],
            [12345.678],
            [123.456789],
        ];
    }

    /**
     * @return list<array{0:float}>
     */
    public static function rejectedPriceProvider(): array
    {
        return [
            [123.4567891],
            [0.0000001],
            [1e-15],
            [INF],
        ];
    }

    #[Test]
    #[DataProvider('acceptedPriceProvider')]
    public function supported_prices_are_accepted_without_local_rejection(float $price): void
    {
        $fixture = $this->simpleWriteFixture();

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', price: $price));

        $this->assertSame('known_applied', $result->getAppliedState());
        $this->assertSame('safe_sync_simple_product_write_applied', $result->getReasonCode());
        $this->assertTrue($result->getPostconditionVerified());
        $this->assertSame(1, $result->getConsequentialWriteAttempts());
        $this->assertSame($price, $fixture['product']->getPrice());
        $this->assertSame(['begin', 'begin', 'commit', 'commit'], $fixture['connection']->txEvents);
    }

    #[Test]
    #[DataProvider('rejectedPriceProvider')]
    public function unsupported_prices_are_rejected_before_any_mutation_activity(float $price): void
    {
        $fixture = $this->simpleWriteFixture();

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', price: $price));

        $this->assertSame('known_not_applied', $result->getAppliedState());
        $this->assertSame('safe_sync_invalid_price_precision', $result->getReasonCode());
        $this->assertFalse($result->getPostconditionVerified());
        $this->assertSame(0, $result->getConsequentialWriteAttempts());
        $this->assertSame(0, $fixture['repository']->saveCalls);
        $this->assertSame([], $fixture['connection']->txEvents);
        $this->assertSame([], $fixture['connection']->queries);
        $this->assertSame(0, $fixture['callbackHandler']->processCalls);
        $this->assertSame(0, $fixture['callbackHandler']->clearCalls);
    }

    #[Test]
    public function identifier_removed_before_save_blocks_create_fallback_and_rolls_back(): void
    {
        $product = new FakeProduct(entityId: 77, sku: 'SKU-77', typeId: 'simple', name: 'Original');
        $product->dropIdentifierOnWrite = true;
        $fixture = $this->simpleWriteFixture(product: $product);

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', status: 2));

        $this->assertSame('safe_sync_identifier_missing_before_save', $result->getReasonCode());
        $this->assertSame(['begin', 'rollback'], $fixture['connection']->txEvents);
        $this->assertSame(1, $fixture['callbackHandler']->clearCalls);
    }

    #[Test]
    public function product_write_unsets_media_gallery_before_repository_save_without_other_media_mutation(): void
    {
        $fixture = $this->simpleWriteFixture();

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated Name'));

        $this->assertSame('known_applied', $result->getAppliedState());
        $this->assertSame(['media_gallery'], $fixture['product']->unsetDataCalls);
        $this->assertSame(0, $fixture['product']->mediaGalleryEntriesSetCalls);
        $this->assertSame(0, $fixture['product']->origDataWriteCalls);
    }

    #[Test]
    public function repository_save_return_value_is_not_safety_authority_and_postcondition_is_force_reloaded(): void
    {
        $fixture = $this->simpleWriteFixture();
        $fixture['repository']->saveReturnProduct = new FakeProduct(entityId: 999, sku: 'WRONG', typeId: 'simple', name: 'Wrong');

        $result = $fixture['management']->writeSimpleProduct(
            77,
            $this->writeRequest('SKU-77', name: 'Updated Name', status: 2, visibility: 4, price: 19.99),
        );

        $this->assertSame('known_applied', $result->getAppliedState());
        $this->assertSame('safe_sync_simple_product_write_applied', $result->getReasonCode());
        $this->assertSame([77, 77], $fixture['repository']->getByIdCalls);
        $this->assertSame(1, $fixture['repository']->saveCalls);
    }

    #[Test]
    public function known_applied_response_uses_the_fresh_post_save_product_sku_observation(): void
    {
        $fixture = $this->simpleWriteFixture();
        $fixture['repository']->postSaveProduct = new FakeProduct(
            entityId: 77,
            sku: 'SKU-77',
            typeId: 'simple',
            name: 'Updated Name',
        );

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated Name'));

        $this->assertSame('known_applied', $result->getAppliedState());
        $this->assertSame('SKU-77', $result->getSku());
        $this->assertSame(2, $fixture['repository']->postSaveProduct->skuReads);
    }

    #[Test]
    public function silent_post_save_sku_mismatch_rolls_back_and_clears_callbacks(): void
    {
        $fixture = $this->simpleWriteFixture();
        $fixture['repository']->afterSaveMutation = static function (FakeProduct $product): void {
            $product->setSku('SKU-CHANGED');
        };

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated Name'));

        $this->assertSame('known_not_applied', $result->getAppliedState());
        $this->assertSame('safe_sync_postcondition_sku_mismatch', $result->getReasonCode());
        $this->assertSame(['begin', 'begin', 'commit', 'rollback'], $fixture['connection']->txEvents);
        $this->assertSame(1, $fixture['callbackHandler']->clearCalls);
    }

    #[Test]
    public function sku_ownership_checks_use_locking_current_reads_before_mutation_and_before_commit(): void
    {
        $fixture = $this->simpleWriteFixture();

        $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated Name'));

        $lockingQuery = "SELECT DISTINCT `entity_id` FROM `catalog_product_entity` WHERE `sku` = 'SKU-77' LIMIT 2 FOR UPDATE";
        $lockingReads = array_values(array_filter(
            $fixture['connection']->fetchHistory,
            static fn (string $query): bool => $query === $lockingQuery,
        ));

        $this->assertCount(2, $lockingReads);
    }

    #[Test]
    public function successful_mutation_commits_outer_transaction_then_processes_callbacks(): void
    {
        $fixture = $this->simpleWriteFixture();

        $result = $fixture['management']->writeSimpleProduct(
            77,
            $this->writeRequest('SKU-77', name: 'Updated Name', status: 2, visibility: 4, price: 19.99),
        );

        $this->assertSame('known_applied', $result->getAppliedState());
        $this->assertTrue($result->getPostconditionVerified());
        $this->assertSame(1, $result->getConsequentialWriteAttempts());
        $this->assertSame(['begin', 'begin', 'commit', 'commit'], $fixture['connection']->txEvents);
        $this->assertSame(1, $fixture['callbackHandler']->processCalls);
        $this->assertSame(0, $fixture['callbackHandler']->clearCalls);
        $this->assertSame('Updated Name', $fixture['product']->getName());
        $this->assertSame(2, $fixture['product']->getStatus());
        $this->assertSame([], $fixture['defaultConnection']->fetchHistory);
        $this->assertSame([], $fixture['defaultConnection']->queries);
        $this->assertSame([], $fixture['defaultConnection']->txEvents);
        $this->assertSame(0, $fixture['connection']->getTransactionLevel());
    }

    #[Test]
    public function post_commit_callback_failure_remains_known_applied_with_safe_warning(): void
    {
        $fixture = $this->simpleWriteFixture();
        $fixture['callbackHandler']->processFailure = new \RuntimeException('callback failed');

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated Name'));

        $this->assertSame('known_applied', $result->getAppliedState());
        $this->assertSame(['safe_sync_post_commit_callback_failed'], $result->getWarningCodes());
        $this->assertSame(['begin', 'begin', 'commit', 'commit'], $fixture['connection']->txEvents);
    }

    #[Test]
    public function commit_uncertainty_returns_unknown_or_ambiguous_without_retry(): void
    {
        $fixture = $this->simpleWriteFixture(galeraEnabled: true);
        $fixture['connection']->commitFailure = new \RuntimeException('commit uncertain');

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated Name'));

        $this->assertSame('unknown_or_ambiguous', $result->getAppliedState());
        $this->assertSame('safe_sync_commit_uncertain', $result->getReasonCode());
        $this->assertSame(1, $result->getConsequentialWriteAttempts());
        $this->assertSame(1, $fixture['repository']->saveCalls);
        $this->assertSame(['begin', 'begin', 'commit', 'commit'], $fixture['connection']->txEvents);
        $this->assertSame(0, $fixture['connection']->getTransactionLevel());
        $this->assertSame(0, $fixture['callbackHandler']->processCalls);
        $this->assertSame(0, $fixture['callbackHandler']->clearCalls);
        $this->assertSame(1, $fixture['connection']->resetCalls);
        $this->assertSame([spl_object_hash($fixture['connection'])], CallbackPool::$clearedHashes);
        $this->assertSame(['SET SESSION wsrep_sync_wait = 1'], $fixture['connection']->queries);
    }

    #[Test]
    public function commit_uncertainty_with_zero_transaction_level_clears_pending_callbacks_without_quarantine(): void
    {
        $fixture = $this->simpleWriteFixture(galeraEnabled: true);
        $fixture['connection']->commitFailure = new \RuntimeException('commit uncertain');
        $fixture['connection']->dropToLevelZeroOnCommitFailure = true;

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated Name'));

        $this->assertSame('unknown_or_ambiguous', $result->getAppliedState());
        $this->assertSame('safe_sync_commit_uncertain', $result->getReasonCode());
        $this->assertSame(1, $fixture['callbackHandler']->clearCalls);
        $this->assertSame(0, $fixture['connection']->resetCalls);
        $this->assertSame([], CallbackPool::$clearedHashes);
    }

    #[Test]
    public function successful_commit_without_physical_level_zero_is_unknown_or_ambiguous_and_quarantined(): void
    {
        $fixture = $this->simpleWriteFixture(galeraEnabled: true);
        $fixture['repository']->simulateDanglingNestedTransaction = true;

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated Name'));

        $this->assertSame('unknown_or_ambiguous', $result->getAppliedState());
        $this->assertSame('safe_sync_commit_uncertain', $result->getReasonCode());
        $this->assertTrue($result->getPostconditionVerified());
        $this->assertSame(1, $result->getConsequentialWriteAttempts());
        $this->assertSame([], $result->getWarningCodes());
        $this->assertSame(0, $fixture['callbackHandler']->processCalls);
        $this->assertSame(0, $fixture['callbackHandler']->clearCalls);
        $this->assertSame([spl_object_hash($fixture['connection'])], CallbackPool::$clearedHashes);
        $this->assertSame([
            'clear:'.spl_object_hash($fixture['connection']),
            'reset:'.spl_object_hash($fixture['connection']),
        ], CallbackPool::$events);
        $this->assertSame(1, $fixture['connection']->resetCalls);
        $this->assertSame(0, $fixture['connection']->getTransactionLevel());
        $this->assertSame(['SET SESSION wsrep_sync_wait = 1'], $fixture['connection']->queries);
        $this->assertSame(['begin', 'begin', 'begin', 'commit', 'commit'], $fixture['connection']->txEvents);
    }

    #[Test]
    public function rollback_exception_after_consequential_save_attempt_returns_unknown_or_ambiguous_once_and_quarantines_exact_entity_connection(): void
    {
        $fixture = $this->simpleWriteFixture();
        $fixture['repository']->afterSaveMutation = static function (FakeProduct $product): void {
            $product->setSku('SKU-CHANGED');
        };
        $fixture['connection']->rollbackFailure = new \RuntimeException('rollback failed');

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated Name'));

        $this->assertSame('unknown_or_ambiguous', $result->getAppliedState());
        $this->assertSame('safe_sync_rollback_uncertain', $result->getReasonCode());
        $this->assertSame(1, $result->getConsequentialWriteAttempts());
        $this->assertSame(1, $fixture['connection']->resetCalls);
        $this->assertSame([spl_object_hash($fixture['connection'])], CallbackPool::$clearedHashes);
        $this->assertSame(['begin', 'begin', 'commit'], $fixture['connection']->txEvents);
    }

    #[Test]
    public function nested_repository_rollback_poison_requires_a_successful_bridge_owned_outer_rollback(): void
    {
        $fixture = $this->simpleWriteFixture();
        $fixture['repository']->simulateNestedRollbackPoison = true;

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated Name'));

        $this->assertSame('known_not_applied', $result->getAppliedState());
        $this->assertSame('safe_sync_repository_save_failed', $result->getReasonCode());
        $this->assertSame(1, $result->getConsequentialWriteAttempts());
        $this->assertSame(['begin', 'begin', 'rollback', 'rollback'], $fixture['connection']->txEvents);
        $this->assertSame(0, $fixture['connection']->getTransactionLevel());
        $this->assertFalse($fixture['connection']->isRolledBack);
        $this->assertSame(1, $fixture['callbackHandler']->clearCalls);
        $this->assertSame(0, $fixture['callbackHandler']->processCalls);
    }

    #[Test]
    public function galera_begin_failure_is_known_not_applied_with_zero_writes_and_exact_entity_connection_quarantine(): void
    {
        $fixture = $this->simpleWriteFixture(galeraEnabled: true);
        $fixture['connection']->beginFailure = new \RuntimeException('begin failed');

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated Name'));

        $this->assertSame('known_not_applied', $result->getAppliedState());
        $this->assertSame('safe_sync_begin_failed', $result->getReasonCode());
        $this->assertSame(0, $result->getConsequentialWriteAttempts());
        $this->assertSame(0, $fixture['repository']->saveCalls);
        $this->assertSame(['begin'], $fixture['connection']->txEvents);
        $this->assertSame(0, $fixture['connection']->getTransactionLevel());
        $this->assertSame(1, $fixture['connection']->resetCalls);
        $this->assertSame([spl_object_hash($fixture['connection'])], CallbackPool::$clearedHashes);
        $this->assertSame(0, $fixture['callbackHandler']->processCalls);
        $this->assertSame(0, $fixture['callbackHandler']->clearCalls);
        $this->assertSame(['SET SESSION wsrep_sync_wait = 1'], $fixture['connection']->queries);
    }

    #[Test]
    public function unsupported_connection_quarantine_seam_fails_closed_before_any_write_attempt(): void
    {
        $fixture = $this->simpleWriteFixture(supportsReset: false);

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated Name'));

        $this->assertSame('known_not_applied', $result->getAppliedState());
        $this->assertSame('safe_sync_connection_quarantine_unavailable', $result->getReasonCode());
        $this->assertSame(0, $result->getConsequentialWriteAttempts());
        $this->assertSame(0, $fixture['repository']->saveCalls);
    }

    #[Test]
    public function quarantine_warning_codes_surface_callback_clear_failure(): void
    {
        $fixture = $this->simpleWriteFixture(galeraEnabled: true, callbackPoolClearFailure: new \RuntimeException('clear failed'));
        $fixture['connection']->beginFailure = new \RuntimeException('begin failed');

        $result = $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated Name'));

        $this->assertSame('known_not_applied', $result->getAppliedState());
        $this->assertSame('safe_sync_begin_failed', $result->getReasonCode());
        $this->assertSame([
            'safe_sync_callback_pool_clear_failed',
            'safe_sync_connection_quarantine_failed',
        ], $result->getWarningCodes());
        $this->assertSame(1, $fixture['connection']->resetCalls);
    }

    #[Test]
    public function connection_quarantine_clears_exact_callback_pool_before_reset(): void
    {
        $callbackBridge = new ProductEntityManagerCallbackBridge(new CallbackHandler);
        $quarantine = new ConnectionQuarantine($callbackBridge);
        $connection = new ResettableFakeAdapter([]);

        $result = $quarantine->quarantine($connection);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['callback_clear_failed']);
        $this->assertSame([
            'clear:'.spl_object_hash($connection),
            'reset:'.spl_object_hash($connection),
        ], CallbackPool::$events);
    }

    #[Test]
    public function galera_restore_with_open_transaction_quarantines_before_throwing_original_precondition_failure(): void
    {
        $callbackBridge = new ProductEntityManagerCallbackBridge(new CallbackHandler);
        $connection = new ResettableFakeAdapter([]);
        $connection->transactionLevel = 1;
        $session = new GaleraWriteSession(new ConnectionQuarantine($callbackBridge));

        try {
            $session->restore($connection, ['previous' => 0]);
            $this->fail('Expected restore precondition failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('safe_sync_wsrep_restore_before_transaction_level_zero', $exception->getMessage());
        }

        $this->assertSame([
            'clear:'.spl_object_hash($connection),
            'reset:'.spl_object_hash($connection),
        ], CallbackPool::$events);
        $this->assertSame(1, $connection->resetCalls);
        $this->assertSame(0, $connection->getTransactionLevel());
        $this->assertSame([], $connection->queries);
    }

    #[Test]
    public function galera_restore_with_open_transaction_preserves_quarantine_failure_diagnostics(): void
    {
        $callbackBridge = new ProductEntityManagerCallbackBridge(new CallbackHandler);
        $connection = new ResettableFakeAdapter([]);
        $connection->transactionLevel = 1;
        $connection->resetFailure = new \RuntimeException('reset failed');
        $session = new GaleraWriteSession(new ConnectionQuarantine($callbackBridge));

        try {
            $session->restore($connection, ['previous' => 0]);
            $this->fail('Expected quarantine failure diagnostics.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('safe_sync_wsrep_connection_quarantine_failed:reset_failed', $exception->getMessage());
            $this->assertSame('safe_sync_wsrep_restore_before_transaction_level_zero', $exception->getPrevious()?->getMessage());
        }

        $this->assertSame([
            'clear:'.spl_object_hash($connection),
            'reset:'.spl_object_hash($connection),
        ], CallbackPool::$events);
        $this->assertSame([], $connection->queries);
    }

    #[Test]
    public function galera_write_scope_sets_session_before_begin_and_restores_only_after_level_zero(): void
    {
        $fixture = $this->simpleWriteFixture(galeraEnabled: true);

        $fixture['management']->writeSimpleProduct(77, $this->writeRequest('SKU-77', name: 'Updated Name'));

        $this->assertContains("SHOW SESSION VARIABLES LIKE 'wsrep_sync_wait'", $fixture['connection']->fetchHistory);
        $this->assertSame('SET SESSION wsrep_sync_wait = 1', $fixture['connection']->queries[0] ?? null);
        $this->assertSame('SET SESSION wsrep_sync_wait = 0', $fixture['connection']->queries[count($fixture['connection']->queries) - 1] ?? null);
        $this->assertSame(0, $fixture['connection']->getTransactionLevel());
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

            public function save($product, $saveOptions = false)
            {
                return $product;
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

    /**
     * @return array{
     *   management:ProductWriteManagement,
     *   repository:FakeProductRepository,
     *   connection:FakeAdapter,
     *   defaultConnection:FakeAdapter,
     *   callbackHandler:CallbackHandler,
     *   product:FakeProduct,
     *   logger:FakeLogger
     * }
     */
    private function simpleWriteFixture(
        ?FakeProduct $product = null,
        ?array $indexRows = null,
        ?array $lockRows = null,
        ?array $skuRows = null,
        bool $galeraEnabled = false,
        ?array $mediaAttributeCodes = null,
        ?\Throwable $mediaConfigFailure = null,
        bool $supportsReset = true,
        ?\Throwable $callbackPoolClearFailure = null,
    ): array {
        $product ??= new FakeProduct(entityId: 77, sku: 'SKU-77', typeId: 'simple', name: 'Original');
        $connection = $supportsReset
            ? new ResettableFakeAdapter([
                'SHOW INDEX FROM `catalog_product_entity`' => $indexRows ?? [
                    ['Column_name' => 'entity_id', 'Seq_in_index' => 1],
                    ['Column_name' => 'sku', 'Seq_in_index' => 1],
                ],
                'SELECT `row_id` FROM `catalog_product_entity` WHERE `entity_id` = 77 FOR UPDATE' => $lockRows ?? [['row_id' => 7001]],
                "SELECT DISTINCT `entity_id` FROM `catalog_product_entity` WHERE `sku` = 'SKU-77' LIMIT 2 FOR UPDATE" => $skuRows ?? [['entity_id' => 77]],
                "SHOW VARIABLES LIKE 'wsrep_provider'" => $galeraEnabled ? ['Value' => '/usr/lib/libgalera_smm.so'] : null,
                "SHOW SESSION VARIABLES LIKE 'wsrep_on'" => ['Value' => 'ON'],
                "SHOW SESSION VARIABLES LIKE 'wsrep_dirty_reads'" => ['Value' => 'OFF'],
                "SHOW STATUS LIKE 'wsrep_connected'" => ['Value' => 'ON'],
                "SHOW STATUS LIKE 'wsrep_ready'" => ['Value' => 'ON'],
                "SHOW STATUS LIKE 'wsrep_cluster_status'" => ['Value' => 'Primary'],
                "SHOW SESSION VARIABLES LIKE 'wsrep_sync_wait'" => ['Value' => '0'],
            ])
            : new FakeAdapter([
                'SHOW INDEX FROM `catalog_product_entity`' => $indexRows ?? [
                    ['Column_name' => 'entity_id', 'Seq_in_index' => 1],
                    ['Column_name' => 'sku', 'Seq_in_index' => 1],
                ],
                'SELECT `row_id` FROM `catalog_product_entity` WHERE `entity_id` = 77 FOR UPDATE' => $lockRows ?? [['row_id' => 7001]],
                "SELECT DISTINCT `entity_id` FROM `catalog_product_entity` WHERE `sku` = 'SKU-77' LIMIT 2 FOR UPDATE" => $skuRows ?? [['entity_id' => 77]],
                "SHOW VARIABLES LIKE 'wsrep_provider'" => $galeraEnabled ? ['Value' => '/usr/lib/libgalera_smm.so'] : null,
                "SHOW SESSION VARIABLES LIKE 'wsrep_on'" => ['Value' => 'ON'],
                "SHOW SESSION VARIABLES LIKE 'wsrep_dirty_reads'" => ['Value' => 'OFF'],
                "SHOW STATUS LIKE 'wsrep_connected'" => ['Value' => 'ON'],
                "SHOW STATUS LIKE 'wsrep_ready'" => ['Value' => 'ON'],
                "SHOW STATUS LIKE 'wsrep_cluster_status'" => ['Value' => 'Primary'],
                "SHOW SESSION VARIABLES LIKE 'wsrep_sync_wait'" => ['Value' => '0'],
            ]);
        $defaultConnection = new FakeAdapter([]);
        $repository = new FakeProductRepository($product, $connection);
        $metadata = new class($connection)
        {
            public function __construct(
                private readonly FakeAdapter $connection,
            ) {}

            public function getIdentifierField(): string
            {
                return 'entity_id';
            }

            public function getLinkField(): string
            {
                return 'row_id';
            }

            public function getEntityTable(): string
            {
                return 'catalog_product_entity';
            }

            public function getEntityConnectionName(): ?string
            {
                return 'catalog';
            }

            public function getEntityConnection(): FakeAdapter
            {
                return $this->connection;
            }
        };
        $callbackHandler = new CallbackHandler;
        if ($callbackPoolClearFailure !== null) {
            CallbackPool::$failures[spl_object_hash($connection)] = $callbackPoolClearFailure;
        }
        $resourceConnection = new ResourceConnection($defaultConnection, null, ['catalog' => $connection]);
        $logger = new FakeLogger;
        $callbackBridge = new ProductEntityManagerCallbackBridge($callbackHandler);
        $connectionQuarantine = new ConnectionQuarantine($callbackBridge);
        $management = new ProductWriteManagement(
            $repository,
            new MetadataPool($metadata),
            $resourceConnection,
            new ProductWriteResponseFactory,
            $mediaConfigFailure !== null ? new ProductMediaConfig($mediaConfigFailure) : new ProductMediaConfig($mediaAttributeCodes ?? ['image', 'small_image', 'thumbnail']),
            new GaleraWriteSession($connectionQuarantine),
            $connectionQuarantine,
            new NonMediaProductWriteScope,
            $callbackBridge,
            $logger,
        );

        return [
            'management' => $management,
            'repository' => $repository,
            'connection' => $connection,
            'defaultConnection' => $defaultConnection,
            'callbackHandler' => $callbackHandler,
            'product' => $product,
            'logger' => $logger,
        ];
    }

    private function writeRequest(
        string $expectedSku,
        ?string $name = null,
        ?int $status = null,
        ?int $visibility = null,
        ?float $price = null,
        array $mappedAttributes = [],
    ): ProductWriteRequest {
        return (new ProductWriteRequest)
            ->setExpectedSku($expectedSku)
            ->setName($name)
            ->setStatus($status)
            ->setVisibility($visibility)
            ->setPrice($price)
            ->setMappedAttributes($mappedAttributes);
    }

    private function mappedAttribute(string $attributeCode, string $value): ProductWriteMappedAttribute
    {
        return (new ProductWriteMappedAttribute)
            ->setAttributeCode($attributeCode)
            ->setValue($value);
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

class FakeAdapter implements AdapterInterface
{
    /** @var array<string, mixed> */
    private array $rows;

    /** @var array<string, \Throwable> */
    private array $queryFailures;

    /** @var array<string, \Throwable> */
    private array $fetchFailures;

    /** @var list<string> */
    public array $queries = [];

    /** @var list<string> */
    public array $fetchHistory = [];

    /** @var list<string> */
    public array $txEvents = [];

    public int $transactionLevel = 0;

    public ?\Throwable $beginFailure = null;

    public ?\Throwable $commitFailure = null;

    public ?\Throwable $rollbackFailure = null;

    public bool $dropToLevelZeroOnCommitFailure = false;

    public bool $isRolledBack = false;

    public int $closeCalls = 0;

    /**
     * @param  array<string, mixed>  $rows
     * @param  array<string, \Throwable>  $queryFailures
     * @param  array<string, \Throwable>  $fetchFailures
     */
    public function __construct(array $rows, array $queryFailures = [], array $fetchFailures = [])
    {
        $this->rows = $rows;
        $this->queryFailures = $queryFailures;
        $this->fetchFailures = $fetchFailures;
    }

    public function fetchRow($sql)
    {
        $this->fetchHistory[] = $sql;

        if (isset($this->fetchFailures[$sql])) {
            throw $this->fetchFailures[$sql];
        }

        return $this->rows[$sql] ?? null;
    }

    public function fetchAll($sql): array
    {
        $this->fetchHistory[] = $sql;

        if (isset($this->fetchFailures[$sql])) {
            throw $this->fetchFailures[$sql];
        }

        $rows = $this->rows[$sql] ?? [];

        return is_array($rows) ? $rows : [];
    }

    public function query($sql)
    {
        $this->queries[] = $sql;

        if (isset($this->queryFailures[$sql])) {
            throw $this->queryFailures[$sql];
        }

        return null;
    }

    public function beginTransaction(): void
    {
        $this->txEvents[] = 'begin';

        if ($this->beginFailure !== null) {
            throw $this->beginFailure;
        }

        $this->transactionLevel++;
    }

    public function commit(): void
    {
        $this->txEvents[] = 'commit';

        if ($this->isRolledBack) {
            throw new \RuntimeException('Rolled back transaction has not been completed correctly.');
        }

        if ($this->transactionLevel === 1 && $this->commitFailure !== null) {
            if ($this->dropToLevelZeroOnCommitFailure) {
                $this->transactionLevel = 0;
            }

            throw $this->commitFailure;
        }

        if ($this->transactionLevel > 0) {
            $this->transactionLevel--;
        }
    }

    public function rollBack(): void
    {
        if ($this->rollbackFailure !== null) {
            throw $this->rollbackFailure;
        }

        $this->txEvents[] = 'rollback';

        if ($this->transactionLevel > 1) {
            $this->transactionLevel--;
            $this->isRolledBack = true;

            return;
        }

        if ($this->transactionLevel === 1) {
            $this->transactionLevel = 0;
            $this->isRolledBack = false;
        }
    }

    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    public function quote(string $value): string
    {
        return "'".$value."'";
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`'.$identifier.'`';
    }

    public function closeConnection(): void
    {
        $this->closeCalls++;
    }
}

final class ResettableFakeAdapter extends FakeAdapter implements ResetAfterRequestInterface
{
    public int $resetCalls = 0;

    public ?\Throwable $resetFailure = null;

    public function _resetState(): void
    {
        CallbackPool::$events[] = 'reset:'.spl_object_hash($this);
        $this->resetCalls++;

        if ($this->resetFailure !== null) {
            throw $this->resetFailure;
        }

        $this->transactionLevel = 0;
        $this->isRolledBack = false;
    }
}

final class FakeProduct implements ProductInterface
{
    public bool $dropIdentifierOnWrite = false;

    public int $skuReads = 0;

    /** @var list<string> */
    public array $unsetDataCalls = [];

    public int $mediaGalleryEntriesSetCalls = 0;

    public int $origDataWriteCalls = 0;

    public function __construct(
        private ?int $entityId,
        private string $sku,
        private string $typeId,
        private string $name,
        private int $status = 1,
        private int $visibility = 1,
        private float $price = 9.99,
        /** @var array<string, string> */
        private array $customAttributes = [],
    ) {}

    public function getData(string $key): mixed
    {
        return match ($key) {
            'entity_id' => $this->entityId,
            default => $this->customAttributes[$key] ?? null,
        };
    }

    public function setData(string $key, mixed $value): self
    {
        if ($key === 'entity_id') {
            $this->entityId = $value === null ? null : (int) $value;

            return $this;
        }

        if (is_scalar($value)) {
            $this->customAttributes[$key] = (string) $value;
        }

        return $this;
    }

    public function unsetData(string $key): self
    {
        $this->unsetDataCalls[] = $key;
        unset($this->customAttributes[$key]);

        return $this;
    }

    public function getSku(): string
    {
        $this->skuReads++;

        return $this->sku;
    }

    public function setSku(string $sku): self
    {
        $this->sku = $sku;

        return $this;
    }

    public function getTypeId(): string
    {
        return $this->typeId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        if ($this->dropIdentifierOnWrite) {
            $this->entityId = null;
        }

        $this->status = $status;

        return $this;
    }

    public function getVisibility(): int
    {
        return $this->visibility;
    }

    public function setVisibility(int $visibility): self
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function setCustomAttribute(string $attributeCode, string $value): self
    {
        $this->customAttributes[$attributeCode] = $value;

        return $this;
    }

    public function getCustomAttribute(string $attributeCode): ?object
    {
        if (! array_key_exists($attributeCode, $this->customAttributes)) {
            return null;
        }

        return new class($this->customAttributes[$attributeCode])
        {
            public function __construct(
                private readonly string $value,
            ) {}

            public function getValue(): string
            {
                return $this->value;
            }
        };
    }

    public function setMediaGalleryEntries(?array $entries): self
    {
        $this->mediaGalleryEntriesSetCalls++;

        return $this;
    }

    public function setOrigData(string $key, mixed $value): self
    {
        $this->origDataWriteCalls++;

        return $this;
    }
}

final class FakeProductRepository implements ProductRepositoryInterface
{
    public int $saveCalls = 0;

    /** @var list<int> */
    public array $getByIdCalls = [];

    public ?FakeProduct $saveReturnProduct = null;

    public ?FakeProduct $postSaveProduct = null;

    /** @var null|\Closure(FakeProduct): void */
    public ?\Closure $afterSaveMutation = null;

    public bool $simulateNestedRollbackPoison = false;

    public bool $simulateDanglingNestedTransaction = false;

    public function __construct(
        private readonly FakeProduct $product,
        private readonly ?FakeAdapter $connection = null,
    ) {}

    public function getById($productId, $editMode = false, $storeId = null, $forceReload = false)
    {
        $this->getByIdCalls[] = (int) $productId;

        if ($forceReload && count($this->getByIdCalls) > 1 && $this->postSaveProduct !== null) {
            return $this->postSaveProduct;
        }

        if ($this->product->getData('entity_id') === null) {
            throw new NoSuchEntityException('missing');
        }

        return $this->product;
    }

    public function save($product, $saveOptions = false)
    {
        $this->saveCalls++;

        if ($this->connection !== null) {
            $this->connection->beginTransaction();

            if ($this->simulateDanglingNestedTransaction) {
                $this->connection->beginTransaction();
            }

            if ($this->simulateNestedRollbackPoison) {
                $this->connection->rollBack();

                throw new \RuntimeException('nested rollback poison');
            }

            $this->connection->commit();
        }

        if ($this->afterSaveMutation !== null) {
            ($this->afterSaveMutation)($this->product);
        }

        return $this->saveReturnProduct ?? $product;
    }
}

final class FakeLogger implements LoggerInterface
{
    /** @var list<array{level:string,message:string}> */
    public array $records = [];

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }
}

final class FakeProductCollection implements \IteratorAggregate
{
    /** @var list<FakeProduct> */
    private array $products;

    /** @var list<FakeProduct> */
    private array $filteredProducts;

    /**
     * @param  list<FakeProduct>  $products
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
