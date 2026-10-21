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
        $url = 'file://'.$output.'/b2b-platform-magento-safe-sync-0.2.1.zip';
        $process = new Process(['bash', 'scripts/package-magento-safe-sync.sh', $output, $url], base_path());
        $process->mustRun();

        $artifact = $output.'/b2b-platform-magento-safe-sync-0.2.1.zip';
        $metadata = json_decode(File::get($output.'/packages.json'), true, flags: JSON_THROW_ON_ERROR);
        $package = $metadata['packages']['b2b-platform/magento-safe-sync']['0.2.1'];
        $sourceManifest = json_decode(File::get(base_path('integrations/magento-safe-sync/composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFileExists($artifact);
        $this->assertSame('magento2-module', $package['type']);
        $this->assertSame($sourceManifest['require'], $package['require']);
        $this->assertSame($sourceManifest['autoload'], $package['autoload']);
        $this->assertSame(['registration.php'], $package['autoload']['files']);
        $this->assertSame('', $package['autoload']['psr-4']['B2BPlatform\\MagentoSafeSync\\']);
        $this->assertSame($url, $package['dist']['url']);
        $this->assertSame(hash_file('sha1', $artifact), $package['dist']['shasum']);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($artifact));
        $this->assertNotFalse($zip->locateName('composer.json'));
        $this->assertNotFalse($zip->locateName('registration.php'));
        $this->assertNotFalse($zip->locateName('etc/module.xml'));
        $this->assertNotFalse($zip->locateName('etc/webapi.xml'));
        $zip->close();

        $consumer = $output.'/consumer';
        File::ensureDirectoryExists($consumer);
        File::put($consumer.'/composer.json', json_encode([
            'name' => 'certification/magento-target',
            'repositories' => [
                ['type' => 'composer', 'url' => 'file://'.$output],
                ['type' => 'package', 'package' => ['name' => 'magento/framework', 'version' => '103.0.8-p5', 'type' => 'metapackage']],
                ['type' => 'package', 'package' => ['name' => 'magento/module-catalog', 'version' => '104.0.8-p5', 'type' => 'metapackage']],
            ],
            'require' => ['b2b-platform/magento-safe-sync' => '0.2.1'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        (new Process([
            'composer', 'install', '--ignore-platform-req=php', '--no-interaction', '--no-plugins',
        ], $consumer))->setTimeout(120)->mustRun();

        $autoloadFiles = require $consumer.'/vendor/composer/autoload_files.php';
        $autoloadPsr4 = require $consumer.'/vendor/composer/autoload_psr4.php';
        $installedRegistration = realpath($consumer.'/vendor/b2b-platform/magento-safe-sync/registration.php');

        $this->assertContains($installedRegistration, array_map('realpath', array_values($autoloadFiles)));
        $this->assertContains(
            realpath($consumer.'/vendor/b2b-platform/magento-safe-sync'),
            array_map('realpath', $autoloadPsr4['B2BPlatform\\MagentoSafeSync\\']),
        );

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
