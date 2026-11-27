<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MagentoSafeSyncModuleContractTest extends TestCase
{
    private string $moduleBasePath = 'integrations/magento-safe-sync';

    #[Test]
    public function module_package_is_a_standalone_magento_two_composer_module(): void
    {
        $composer = json_decode(
            File::get(base_path($this->moduleBasePath.'/composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('magento2-module', $composer['type'] ?? null);
        $this->assertSame('b2b-platform/magento-safe-sync', $composer['name'] ?? null);
        $this->assertArrayHasKey('autoload', $composer);
        $this->assertArrayHasKey('psr-4', $composer['autoload']);
        $this->assertArrayHasKey('B2BPlatform\\MagentoSafeSync\\', $composer['autoload']['psr-4']);
    }

    #[Test]
    public function module_advertises_the_verified_adobe_commerce_php_range(): void
    {
        $composer = json_decode(
            File::get(base_path($this->moduleBasePath.'/composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('>=8.4 <8.6', $composer['require']['php'] ?? null);
        $this->assertSame('>=103.0.8-p5 <103.0.10', $composer['require']['magento/framework'] ?? null);
        $this->assertSame('>=104.0.8-p5 <104.0.10', $composer['require']['magento/module-catalog'] ?? null);
        $this->assertStringNotContainsString('>=8.3', json_encode($composer, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('103.0.0', json_encode($composer, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('104.0.0', json_encode($composer, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function module_webapi_routes_require_existing_authenticated_catalog_acl_resource(): void
    {
        $xml = new \SimpleXMLElement(File::get(base_path($this->moduleBasePath.'/etc/webapi.xml')));
        $routes = $xml->xpath('//route');

        $this->assertCount(3, $routes);
        $this->assertSame('/V1/safe-sync/handshake', (string) $routes[0]['url']);
        $this->assertSame('/V1/safe-sync/products/:logicalEntityId', (string) $routes[1]['url']);
        $this->assertSame('/V1/safe-sync/products/:logicalEntityId', (string) $routes[2]['url']);
        $this->assertSame('Magento_Catalog::products', (string) $routes[0]->resources->resource['ref']);
        $this->assertSame('Magento_Catalog::products', (string) $routes[1]->resources->resource['ref']);
        $this->assertSame('Magento_Catalog::products', (string) $routes[2]->resources->resource['ref']);
        $this->assertSame('PUT', (string) $routes[2]['method']);
        $this->assertStringNotContainsString('anonymous', File::get(base_path($this->moduleBasePath.'/etc/webapi.xml')));
    }

    #[Test]
    public function module_product_read_contract_is_entity_bound_and_not_row_id_bound(): void
    {
        $content = File::get(base_path($this->moduleBasePath.'/Model/ProductReadManagement.php'));

        $this->assertStringContainsString('getIdentifierField()', $content);
        $this->assertStringContainsString('getById($logicalEntityId, false, null, true)', $content);
        $this->assertStringContainsString("addAttributeToFilter('sku'", $content);
        $this->assertStringNotContainsString('trim($expectedSku)', $content);
        $this->assertStringNotContainsString('getLinkField()', $content);
        $this->assertStringNotContainsString('row_id', $content);
    }

    #[Test]
    public function galera_session_scope_preserves_previous_wsrep_bits_and_restores_exact_previous_value(): void
    {
        $content = File::get(base_path($this->moduleBasePath.'/Model/GaleraSessionScope.php'));

        $this->assertStringContainsString("'wsrep_provider'", $content);
        $this->assertStringContainsString("'wsrep_on'", $content);
        $this->assertStringContainsString("'wsrep_dirty_reads'", $content);
        $this->assertStringContainsString("'wsrep_connected'", $content);
        $this->assertStringContainsString("'wsrep_ready'", $content);
        $this->assertStringContainsString("'wsrep_cluster_status'", $content);
        $this->assertStringContainsString('$temporary = $previous | 1;', $content);
        $this->assertStringContainsString('$this->setWsrepSyncWait($connection, $previous);', $content);
        $this->assertStringContainsString('$this->resourceConnection->closeConnection();', $content);
        $this->assertStringContainsString("preg_match('/^(?:0|[1-9][0-9]*)$/', \$value)", $content);
        $this->assertStringContainsString('$integerValue > 15', $content);
        $this->assertStringNotContainsString('SET SESSION wsrep_sync_wait = 0', $content);
    }

    #[Test]
    public function handshake_version_must_come_from_authoritative_module_metadata_without_fabricated_fallback(): void
    {
        $content = File::get(base_path($this->moduleBasePath.'/Model/HandshakeManagement.php'));

        $this->assertStringContainsString('safe_sync_module_version_unavailable', $content);
        $this->assertStringNotContainsString("['setup_version'] ?? '0.0.0'", $content);
        $this->assertStringContainsString('SafeSyncContract::SIMPLE_PRODUCT_WRITE_FAMILY', $content);
    }

    #[Test]
    public function module_version_is_bumped_to_point_two_one_without_contract_bump(): void
    {
        $moduleXml = File::get(base_path($this->moduleBasePath.'/etc/module.xml'));
        $contract = File::get(base_path($this->moduleBasePath.'/Model/SafeSyncContract.php'));

        $this->assertStringContainsString('setup_version="0.2.1"', $moduleXml);
        $this->assertStringContainsString("CONTRACT_VERSION = 'stage3e-r1';", $contract);
        $this->assertStringContainsString("SIMPLE_PRODUCT_WRITE_FAMILY = 'entity_bound_simple_product_write';", $contract);
    }

    #[Test]
    public function module_simple_product_write_contract_remains_entity_bound_and_closed_bounded(): void
    {
        $content = File::get(base_path($this->moduleBasePath.'/Model/ProductWriteManagement.php'));
        $quarantine = File::get(base_path($this->moduleBasePath.'/Model/Connection/ConnectionQuarantine.php'));
        $scope = File::get(base_path($this->moduleBasePath.'/Model/Media/NonMediaProductWriteScope.php'));
        $bridge = File::get(base_path($this->moduleBasePath.'/Model/ProductEntityManagerCallbackBridge.php'));

        $this->assertStringContainsString('getIdentifierField()', $content);
        $this->assertStringContainsString('getLinkField()', $content);
        $this->assertStringContainsString('getEntityTable()', $content);
        $this->assertStringContainsString('SELECT %s FROM %s WHERE %s = %d FOR UPDATE', $content);
        $this->assertStringContainsString('LIMIT 2 FOR UPDATE', $content);
        $this->assertStringContainsString('getById($logicalEntityId, false, null, true)', $content);
        $this->assertStringContainsString("'safe_sync_non_simple_product_type'", $content);
        $this->assertStringContainsString("'safe_sync_identifier_index_unavailable'", $content);
        $this->assertStringContainsString("'safe_sync_sku_index_unavailable'", $content);
        $this->assertStringContainsString("'safe_sync_rollback_uncertain'", $content);
        $this->assertStringContainsString("\$product->unsetData('media_gallery')", $content);
        $this->assertStringContainsString('getMediaAttributeCodes()', $content);
        $this->assertStringContainsString("'safe_sync_media_attribute_not_allowed'", $content);
        $this->assertStringContainsString('runForLogicalEntity(', $content);
        $this->assertStringContainsString("'safe_sync_connection_quarantine_unavailable'", $content);
        $this->assertStringContainsString("'safe_sync_commit_uncertain'", $content);
        $this->assertStringContainsString('CallbackPool::clear(spl_object_hash($connection))', $bridge);
        $this->assertStringContainsString('$connection->_resetState()', $quarantine);
        $this->assertStringContainsString('implements ResetAfterRequestInterface', $scope);
        $this->assertStringContainsString('mapped_attributes', $content);
        $this->assertStringNotContainsString('postProduct(', $content);
        $this->assertStringNotContainsString('row_id as identity', $content);
        $this->assertStringNotContainsString("method_exists(\$product, 'unsetData')", $content);
        $this->assertStringNotContainsString('setMediaGalleryEntries(null)', $content);
        $this->assertStringNotContainsString('closeConnection()', $content);
    }

    #[Test]
    public function module_registers_only_the_narrow_gallery_update_handler_plugin_for_non_media_write_scope(): void
    {
        $di = File::get(base_path($this->moduleBasePath.'/etc/di.xml'));

        $this->assertStringContainsString('Magento\Catalog\Model\Product\Gallery\UpdateHandler', $di);
        $this->assertStringContainsString('UpdateHandlerNonMediaBypassPlugin', $di);
        $this->assertStringNotContainsString('Magento\Catalog\Model\ProductRepository', $di);
        $this->assertStringNotContainsString('Magento\Catalog\Api\ProductRepositoryInterface', $di);
        $this->assertStringNotContainsString('Magento\Catalog\Model\Product"', $di);
        $this->assertStringNotContainsString('Magento\Framework\EntityManager', $di);
    }

    #[Test]
    public function module_contains_no_magento_schema_or_trigger_files(): void
    {
        $files = File::allFiles(base_path($this->moduleBasePath));
        $paths = array_map(static fn (\SplFileInfo $file): string => $file->getRelativePathname(), $files);

        $this->assertNotContains('etc/db_schema.xml', $paths);
        $this->assertSame([], preg_grep('/trigger/i', $paths));
    }
}
