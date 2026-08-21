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
    }

    #[Test]
    public function module_webapi_routes_require_existing_authenticated_catalog_acl_resource(): void
    {
        $xml = new \SimpleXMLElement(File::get(base_path($this->moduleBasePath.'/etc/webapi.xml')));
        $routes = $xml->xpath('//route');

        $this->assertCount(2, $routes);
        $this->assertSame('/V1/safe-sync/handshake', (string) $routes[0]['url']);
        $this->assertSame('/V1/safe-sync/products/:logicalEntityId', (string) $routes[1]['url']);
        $this->assertSame('Magento_Catalog::products', (string) $routes[0]->resources->resource['ref']);
        $this->assertSame('Magento_Catalog::products', (string) $routes[1]->resources->resource['ref']);
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
        $this->assertStringContainsString("preg_match('/^(?:0|[1-9][0-9]*)$/', \$value)", $content);
        $this->assertStringContainsString('$integerValue > 15', $content);
        $this->assertStringNotContainsString('SET SESSION wsrep_sync_wait = 0', $content);
    }

    #[Test]
    public function handshake_version_must_come_from_authoritative_module_metadata_without_fabricated_fallback(): void
    {
        $content = File::get(base_path($this->moduleBasePath.'/Model/HandshakeManagement.php'));

        $this->assertStringContainsString("safe_sync_module_version_unavailable", $content);
        $this->assertStringNotContainsString("['setup_version'] ?? '0.0.0'", $content);
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
