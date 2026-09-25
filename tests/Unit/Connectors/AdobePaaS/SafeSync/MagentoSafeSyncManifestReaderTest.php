<?php

namespace Tests\Unit\Connectors\AdobePaaS\SafeSync;

use App\Support\Connectors\AdobePaaS\SafeSync\MagentoSafeSyncManifestReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MagentoSafeSyncManifestReaderTest extends TestCase
{
    #[Test]
    public function requirements_are_sourced_from_the_magento_safe_sync_manifest(): void
    {
        $manifestPath = dirname(__DIR__, 5).'/integrations/magento-safe-sync/composer.json';
        $raw = file_get_contents($manifestPath);
        $this->assertNotFalse($raw);

        $manifest = json_decode($raw, true);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('require', $manifest);
        $this->assertIsArray($manifest['require']);

        $expectedPhp = $manifest['require']['php'] ?? null;
        $expectedFramework = $manifest['require']['magento/framework'] ?? null;
        $expectedCatalog = $manifest['require']['magento/module-catalog'] ?? null;

        $reader = new MagentoSafeSyncManifestReader;
        $requirements = $reader->requirements();

        $this->assertSame($expectedPhp, $requirements->phpConstraint);
        $this->assertSame($expectedFramework, $requirements->magentoFrameworkConstraint);
        $this->assertSame($expectedCatalog, $requirements->magentoCatalogConstraint);
    }
}
