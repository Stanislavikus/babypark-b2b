<?php

namespace Tests\Feature\Sync;

use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Enums\RemoteCatalogScanStatus;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Filament\Pages\Sync\ManageAdobeProductsChannel;
use App\Filament\Pages\Sync\ManageAdobeRemoteCatalog;
use App\Jobs\Connectors\AdobeRemoteCatalogScanJob;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Connectors\RemoteCatalogScanService;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogBoundary;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogPage;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogReadClient;
use App\Support\Connectors\ConnectorAccountOperationLock;
use App\Support\Connectors\RemoteCatalog\RemoteCatalogItemCandidate;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class RemoteCatalogMerchantSurfaceTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->configureAdobeProductsExportSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Export],
        ]);

        $this->workspace = $this->defaultWorkspace();
        $this->actor = $this->createStaffUser(UserRole::Admin);
        $this->grantExactWorkspacePermissions($this->workspace, $this->actor, [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function channel_summary_and_remote_only_surface_use_current_snapshot_and_trusted_links(): void
    {
        $account = $this->createConnectorAccount();
        $linkedProduct = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'LOCAL-LINKED',
            'name' => 'Local linked',
            'is_active' => true,
        ]);

        $scan = app(RemoteCatalogScanService::class)->begin(
            $account,
            SyncDataDomain::Products,
            app(AdobeConnectorAccountTargetSnapshotResolver::class)->resolve($account)->toEnvelopeArray(),
            3,
        );
        app(RemoteCatalogScanService::class)->append($scan, [
            new RemoteCatalogItemCandidate('501', 'REMOTE-501', 'Remote linked'),
            new RemoteCatalogItemCandidate('502', 'REMOTE-502', 'Remote only A'),
            new RemoteCatalogItemCandidate('503', 'REMOTE-503', 'Remote only B'),
        ]);
        app(RemoteCatalogScanService::class)->publish($scan);

        $membership = WorkspaceUser::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('user_id', $this->actor->id)
            ->firstOrFail();

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $linkedProduct->id,
            'product_variant_id' => null,
            'external_identifier' => 'REMOTE-501',
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => '501',
            'established_by_workspace_user_id' => $membership->id,
            'established_at' => now(),
        ]);

        Livewire::actingAs($this->actor)
            ->test(ManageAdobeProductsChannel::class, ['account' => $account->id])
            ->assertSet('hasRemoteCatalogSnapshot', true)
            ->assertSet('remoteCatalogTotal', 3)
            ->assertSet('linkedRemoteCount', 1)
            ->assertSet('remoteOnlyCount', 2)
            ->assertSee(__('product_channels.remote_catalog.summary', [
                'remote' => 3,
                'linked' => 1,
                'unlinked' => 2,
            ]));
        $remoteCatalog = Livewire::actingAs($this->actor)
            ->test(ManageAdobeRemoteCatalog::class, ['account' => $account->id])
            ->assertSet('remoteCatalogTotal', 3)
            ->assertSet('linkedRemoteCount', 1)
            ->assertSet('remoteOnlyCount', 2)
            ->assertSee('Remote only A')
            ->assertSee('Remote only B')
            ->assertDontSee('Remote linked');

        $remoteCatalog
            ->searchTable('REMOTE-502')
            ->assertSee('Remote only A')
            ->assertDontSee('Remote only B');
        $remoteCatalog
            ->searchTable('503')
            ->assertSee('Remote only B')
            ->assertDontSee('Remote only A');
    }

    #[Test]
    public function remote_catalog_merchant_copy_avoids_forbidden_snapshot_and_projection_vocabulary(): void
    {
        $originalLocale = app()->getLocale();

        try {
            foreach ([
                'en' => ['snapshot', 'read-only', 'projection'],
                'uk' => ['знімок', 'read-only', 'проєкц'],
                'ru' => ['снимок', 'read-only', 'проекц'],
            ] as $locale => $forbiddenFragments) {
                app()->setLocale($locale);
                $copy = mb_strtolower(implode(' ', [
                    __('product_channels.remote_catalog.table_description'),
                    __('product_channels.remote_catalog.captured_at', ['time' => 'now']),
                ]));

                foreach ($forbiddenFragments as $fragment) {
                    $this->assertStringNotContainsString($fragment, $copy);
                }
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    #[Test]
    public function remote_only_surface_stops_presenting_old_target_snapshot_after_target_change(): void
    {
        $account = $this->createConnectorAccount();
        $scan = app(RemoteCatalogScanService::class)->begin(
            $account,
            SyncDataDomain::Products,
            app(AdobeConnectorAccountTargetSnapshotResolver::class)->resolve($account)->toEnvelopeArray(),
            1,
        );
        app(RemoteCatalogScanService::class)->append($scan, [
            new RemoteCatalogItemCandidate('601', 'REMOTE-601', 'Old target product'),
        ]);
        app(RemoteCatalogScanService::class)->publish($scan);

        $component = Livewire::actingAs($this->actor)
            ->test(ManageAdobeRemoteCatalog::class, ['account' => $account->id])
            ->assertSet('remoteCatalogTotal', 1)
            ->assertSee('Old target product');

        $account->forceFill(['base_url' => 'https://different.example.com'])->save();

        $component
            ->call('$refresh')
            ->assertSet('snapshotId', null)
            ->assertSet('remoteCatalogTotal', 0)
            ->assertSet('linkedRemoteCount', 0)
            ->assertSet('remoteOnlyCount', 0)
            ->assertDontSee('Old target product');
    }

    #[Test]
    public function refresh_action_dispatches_remote_catalog_scan_to_background_queue(): void
    {
        Bus::fake();
        $account = $this->createConnectorAccount();

        Livewire::actingAs($this->actor)
            ->test(ManageAdobeProductsChannel::class, ['account' => $account->id])
            ->call('refreshRemoteCatalog')
            ->assertSet('remoteCatalogScanRunning', true)
            ->assertNotified();

        Bus::assertDispatched(AdobeRemoteCatalogScanJob::class);
    }

    #[Test]
    public function background_scan_survives_one_shared_lock_contention_before_execution(): void
    {
        $account = $this->createConnectorAccount();
        $executionToken = (string) Str::uuid();

        $this->app->bind(AdobeRemoteCatalogReadClient::class, static fn (): AdobeRemoteCatalogReadClient => new class implements AdobeRemoteCatalogReadClient
        {
            public function captureBoundary(AdobePaaSRequestContext $context): AdobeRemoteCatalogBoundary
            {
                return new AdobeRemoteCatalogBoundary(0, null);
            }

            public function readPage(AdobePaaSRequestContext $context, int $lastSeenEntityId, int $maxEntityId, int $pageSize): AdobeRemoteCatalogPage
            {
                throw new \LogicException('Zero-item boundary must not read a page.');
            }

            public function countWithinBoundary(AdobePaaSRequestContext $context, int $maxEntityId): int
            {
                return 0;
            }
        });

        Queue::connection('database_connectors')->push(new AdobeRemoteCatalogScanJob(
            (string) $this->workspace->id,
            (string) $account->id,
            $executionToken,
        ));

        $lock = Cache::lock(ConnectorAccountOperationLock::cacheKey((string) $account->id), 1100);
        $this->assertTrue($lock->get());

        $worker = app('queue.worker')->setCache(Cache::store());
        $options = new WorkerOptions(timeout: 900, sleep: 0, maxTries: 3);

        $worker->runNextJob('database_connectors', 'connectors', $options);
        $this->assertDatabaseMissing('remote_catalog_scans', ['execution_token' => $executionToken]);

        $lock->release();
        $this->travel(31)->seconds();

        $worker->runNextJob('database_connectors', 'connectors', $options);

        $this->assertDatabaseHas('remote_catalog_scans', [
            'execution_token' => $executionToken,
            'status' => RemoteCatalogScanStatus::Succeeded->value,
        ]);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    #[Test]
    public function background_scan_execution_exception_fails_once_without_full_scan_retry(): void
    {
        $account = $this->createConnectorAccount();

        $this->app->bind(AdobeRemoteCatalogReadClient::class, static fn (): AdobeRemoteCatalogReadClient => new class implements AdobeRemoteCatalogReadClient
        {
            public function captureBoundary(AdobePaaSRequestContext $context): AdobeRemoteCatalogBoundary
            {
                throw new \RuntimeException('remote read failed');
            }

            public function readPage(AdobePaaSRequestContext $context, int $lastSeenEntityId, int $maxEntityId, int $pageSize): AdobeRemoteCatalogPage
            {
                throw new \LogicException('Page read must not be reached.');
            }

            public function countWithinBoundary(AdobePaaSRequestContext $context, int $maxEntityId): int
            {
                throw new \LogicException('Bounded count must not be reached.');
            }
        });

        Queue::connection('database_connectors')->push(new AdobeRemoteCatalogScanJob(
            (string) $this->workspace->id,
            (string) $account->id,
            (string) Str::uuid(),
        ));

        $worker = app('queue.worker')->setCache(Cache::store());
        $worker->runNextJob(
            'database_connectors',
            'connectors',
            new WorkerOptions(timeout: 900, sleep: 0, maxTries: 3),
        );

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('remote_catalog_scans', 0);
    }

    #[Test]
    public function failed_background_job_marks_only_its_running_scan_failed(): void
    {
        $account = $this->createConnectorAccount();
        $ownedToken = (string) Str::uuid();
        $otherToken = (string) Str::uuid();
        $target = app(AdobeConnectorAccountTargetSnapshotResolver::class)->resolve($account)->toEnvelopeArray();
        $ownedScan = app(RemoteCatalogScanService::class)->begin(
            $account,
            SyncDataDomain::Products,
            $target,
            1,
            $ownedToken,
        );
        $otherScan = app(RemoteCatalogScanService::class)->begin(
            $account,
            SyncDataDomain::Products,
            $target,
            1,
            $otherToken,
        );

        (new AdobeRemoteCatalogScanJob(
            (string) $this->workspace->id,
            (string) $account->id,
            $ownedToken,
        ))->failed(new \RuntimeException('worker failed'));

        $ownedScan->refresh();
        $otherScan->refresh();

        $this->assertSame(RemoteCatalogScanStatus::Failed, $ownedScan->status);
        $this->assertSame('remote_catalog_job_failed', $ownedScan->failure_code);
        $this->assertSame(RemoteCatalogScanStatus::Running, $otherScan->status);
        $this->assertNull($otherScan->failure_code);
    }

    #[Test]
    public function failed_background_job_without_owned_scan_does_not_terminalize_another_attempt(): void
    {
        $account = $this->createConnectorAccount();
        $otherToken = (string) Str::uuid();
        $otherScan = app(RemoteCatalogScanService::class)->begin(
            $account,
            SyncDataDomain::Products,
            app(AdobeConnectorAccountTargetSnapshotResolver::class)->resolve($account)->toEnvelopeArray(),
            1,
            $otherToken,
        );

        (new AdobeRemoteCatalogScanJob(
            (string) $this->workspace->id,
            (string) $account->id,
            (string) Str::uuid(),
        ))->failed(new \RuntimeException('boundary read failed'));

        $otherScan->refresh();

        $this->assertSame(RemoteCatalogScanStatus::Running, $otherScan->status);
        $this->assertNull($otherScan->failure_code);
    }
}
