<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MagentoSafeSyncCertificationPackageTest extends TestCase
{
    #[Test]
    public function certification_package_is_reproducible_composer_metadata_over_the_standalone_module(): void
    {
        $output = storage_path('framework/testing/safe-sync-package-'.uniqid());
        $url = 'https://packages.example.test/b2b-platform-magento-safe-sync-0.2.1.zip';
        $process = new Process(['bash', 'scripts/package-magento-safe-sync.sh', $output, $url], base_path());
        $process->mustRun();

        $artifact = $output.'/b2b-platform-magento-safe-sync-0.2.1.zip';
        $metadata = json_decode(File::get($output.'/packages.json'), true, flags: JSON_THROW_ON_ERROR);
        $package = $metadata['packages']['b2b-platform/magento-safe-sync']['0.2.1'];

        $this->assertFileExists($artifact);
        $this->assertSame('magento2-module', $package['type']);
        $this->assertSame('>=8.4 <8.6', $package['require']['php']);
        $this->assertSame('>=103.0.8-p5 <103.0.10', $package['require']['magento/framework']);
        $this->assertSame('>=104.0.8-p5 <104.0.10', $package['require']['magento/module-catalog']);
        $this->assertSame($url, $package['dist']['url']);
        $this->assertSame(hash_file('sha1', $artifact), $package['dist']['shasum']);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($artifact));
        $this->assertNotFalse($zip->locateName('composer.json'));
        $this->assertNotFalse($zip->locateName('registration.php'));
        $this->assertNotFalse($zip->locateName('etc/module.xml'));
        $this->assertNotFalse($zip->locateName('etc/webapi.xml'));
        $zip->close();

        File::deleteDirectory($output);
    }

    #[Test]
    public function private_module_manifest_keeps_single_runtime_version_source_and_frozen_constraints(): void
    {
        $composer = json_decode(File::get(base_path('integrations/magento-safe-sync/composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('version', $composer);
        $this->assertArrayNotHasKey('suggest', $composer);
        $this->assertSame('>=8.4 <8.6', $composer['require']['php']);
        $this->assertSame('>=103.0.8-p5 <103.0.10', $composer['require']['magento/framework']);
        $this->assertSame('>=104.0.8-p5 <104.0.10', $composer['require']['magento/module-catalog']);
    }
}
