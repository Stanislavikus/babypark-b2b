<?php

namespace Tests\Feature\Sync;

use App\Enums\FieldObjectType;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Services\Sync\Receive\ReceiveLiveImportAdmissionService;
use App\Support\Sync\Exceptions\SyncLiveAdmissionException;
use App\Support\Sync\Receive\ReceiveProposal;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ReceiveLiveImportAdmissionServiceTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([WorkspaceSeeder::class, ConnectorFoundationSeeder::class, WorkspaceRbacPermissionSeeder::class]);
        $this->configureAdobePaaSSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Live],
        ]);
    }

    #[Test]
    public function admit_creates_running_import_v2_explicit_product_without_preview_or_queue(): void
    {
        [$account, $configuration, $product, $actor, $proposal] = $this->fixture();

        $run = app(ReceiveLiveImportAdmissionService::class)->admit(
            $actor,
            $account,
            $proposal,
            (string) $product->id,
        );

        $this->assertSame(SyncRunMode::Live, $run->mode);
        $this->assertSame(SyncSemanticOperation::Import, $run->semantic_operation);
        $this->assertSame(SyncRunStatus::Running, $run->status);
        $this->assertSame((string) $configuration->configuration_revision, (string) $run->configuration_revision);
        $this->assertSame('platform.sync-run-input.v2', $run->configuration_snapshot['version']);
        $this->assertSame('import', $run->configuration_snapshot['semantic_operation']);
        $this->assertSame(['mode' => 'explicit_product', 'product_id' => (string) $product->id], $run->configuration_snapshot['execution_target']);
        $this->assertSame(['mode' => 'all_products'], $run->configuration_snapshot['selection']);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->writer_deadline_at);
        $this->assertNotNull($run->recoverable_after);
        $this->assertNull($run->queued_abandon_after);
        $this->assertNull($run->queue_dispatch_confirmed_at);
        $this->assertDatabaseCount('jobs', 0);
    }

    #[Test]
    public function synchronous_import_admission_does_not_depend_on_queued_grace_configuration(): void
    {
        Config::set('sync_runtime.queued_undispatched_grace_seconds', 0);
        [$account, , $product, $actor, $proposal] = $this->fixture();

        $run = app(ReceiveLiveImportAdmissionService::class)->admit($actor, $account, $proposal, (string) $product->id);

        $this->assertSame(SyncRunStatus::Running, $run->status);
    }

    #[Test]
    public function admission_requires_fresh_run_sync_live_permission(): void
    {
        [$account, , $product, , $proposal] = $this->fixture(false);
        $actor = $this->createStaffUser(UserRole::Manager);

        $this->expectException(SyncLiveAdmissionException::class);

        try {
            app(ReceiveLiveImportAdmissionService::class)->admit($actor, $account, $proposal, (string) $product->id);
        } finally {
            $this->assertSame(0, SyncRun::withoutWorkspaceScope()->count());
        }
    }

    #[Test]
    public function admission_rejects_when_import_live_support_is_still_false(): void
    {
        $this->configureAdobePaaSSyncSupportProfile([]);
        [$account, , $product, $actor, $proposal] = $this->fixture();

        $this->expectException(SyncLiveAdmissionException::class);

        try {
            app(ReceiveLiveImportAdmissionService::class)->admit($actor, $account, $proposal, (string) $product->id);
        } finally {
            $this->assertSame(0, SyncRun::withoutWorkspaceScope()->count());
        }
    }

    #[Test]
    public function admission_rejects_changed_configuration_revision(): void
    {
        [$account, $configuration, $product, $actor, $proposal] = $this->fixture();
        $configuration->configuration_revision = str_repeat('f', 64);
        $configuration->save();

        $this->expectException(SyncLiveAdmissionException::class);

        try {
            app(ReceiveLiveImportAdmissionService::class)->admit($actor, $account, $proposal, (string) $product->id);
        } finally {
            $this->assertSame(0, SyncRun::withoutWorkspaceScope()->count());
        }
    }

    #[Test]
    public function admission_serializes_with_existing_active_run_for_same_configuration(): void
    {
        [$account, $configuration, $product, $actor, $proposal] = $this->fixture();
        SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $configuration->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Queued,
            'configuration_snapshot' => [],
            'queue_dispatch_confirmed_at' => now(),
        ]);

        $this->expectException(SyncLiveAdmissionException::class);

        app(ReceiveLiveImportAdmissionService::class)->admit($actor, $account, $proposal, (string) $product->id);
    }

    private function fixture(bool $grantPermission = true): array
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $configuration = SyncConfiguration::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products,
            'external_context' => [],
            'enabled_operations' => [SyncSemanticOperation::Import->value],
            'operational_state' => SyncConfigurationOperationalState::Enabled,
            'configuration_revision' => str_repeat('a', 64),
        ]);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'RECEIVE-ADMIT-'.Str::upper(Str::random(6)),
            'name' => 'Local Product',
            'is_active' => true,
        ]);
        $actor = $this->createStaffUser(UserRole::Manager);
        if ($grantPermission) {
            $this->grantExactWorkspacePermissions($workspace, $actor, [WorkspacePermissions::RUN_SYNC_LIVE]);
        }
        $proposal = new ReceiveProposal(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            syncConfigurationId: $configuration->id,
            configurationRevision: (string) $configuration->configuration_revision,
            targetType: FieldObjectType::Product,
            targetId: (string) $product->id,
            trustedExternalLinkEvidenceId: 'erl-evidence',
            trustedExternalIdentifier: 'SKU-REMOTE',
            trustedExternalRecordDiscriminator: '77',
            entries: [],
            issuedAt: new DateTimeImmutable,
        );

        return [$account, $configuration, $product, $actor, $proposal];
    }
}
