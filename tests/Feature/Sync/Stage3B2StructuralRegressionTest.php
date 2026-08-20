<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectorAdapter;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class Stage3B2StructuralRegressionTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function sync_live_run_job_remains_fail_closed_without_adobe_command_executor(): void
    {
        $source = file_get_contents(base_path('app/Jobs/Connectors/SyncLiveRunJob.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString('executorNotImplemented', $source);
        $this->assertStringNotContainsString('AdobeProductSimpleCommandExecutor', $source);
    }

    #[Test]
    public function adobe_products_export_live_support_remains_false(): void
    {
        $adapter = new AdobePaaSConnectorAdapter;
        $account = $this->createConnectorAccount();

        $this->assertTrue($adapter->supports(
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
            SyncRunMode::Preview,
        ));

        $this->assertFalse($adapter->supports(
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
            SyncRunMode::Live,
        ));

        $resolver = app(ConnectorSyncSupportResolver::class);
        $this->assertFalse($resolver->supports(
            $account,
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
            SyncRunMode::Live,
        ));
    }

    #[Test]
    public function command_executor_is_not_referenced_from_sync_live_admission_or_job_paths(): void
    {
        $paths = [
            'app/Jobs/Connectors/SyncLiveRunJob.php',
            'app/Services/Sync/SyncLiveAdmissionService.php',
        ];

        foreach ($paths as $path) {
            $contents = file_get_contents(base_path($path));
            $this->assertIsString($contents);
            $this->assertStringNotContainsString('AdobeProductSimpleCommandExecutor', $contents);
        }
    }
}
