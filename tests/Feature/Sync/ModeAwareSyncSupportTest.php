<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ModeAwareSyncSupportTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function adobe_products_export_supports_preview_only(): void
    {
        app()->forgetInstance(ConnectorProfileRegistry::class);
        $account = $this->createConnectorAccount();
        $resolver = app(ConnectorSyncSupportResolver::class);

        $this->assertTrue($resolver->supports($account, SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview));
        $this->assertFalse($resolver->supports($account, SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Live));
        $this->assertTrue($resolver->supportsConfiguration($account, SyncDataDomain::Products, SyncSemanticOperation::Export));
        $this->assertFalse($resolver->supportsConfiguration($account, SyncDataDomain::Products, SyncSemanticOperation::Import));
    }

    #[Test]
    public function test_sync_support_adapter_requires_explicit_mode(): void
    {
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview],
        ]);

        $account = $this->createConnectorAccount(null, ['auth_profile' => 'test_sync_support']);
        $resolver = app(ConnectorSyncSupportResolver::class);

        $this->assertTrue($resolver->supports($account, SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview));
        $this->assertFalse($resolver->supports($account, SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Live));
        $this->assertTrue($resolver->supportsConfiguration($account, SyncDataDomain::Products, SyncSemanticOperation::Export));
    }

    #[Test]
    public function test_sync_support_adapter_can_opt_into_live_support(): void
    {
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview],
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Live],
        ]);

        $account = $this->createConnectorAccount(null, ['auth_profile' => 'test_sync_support']);
        $resolver = app(ConnectorSyncSupportResolver::class);

        $this->assertTrue($resolver->supports($account, SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Live));
    }
}
