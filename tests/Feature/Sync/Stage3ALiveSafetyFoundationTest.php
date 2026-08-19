<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncLiveOutcome;
use App\Enums\SyncPreviewOutcome;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Jobs\Connectors\SyncLiveRunJob;
use App\Jobs\Connectors\SyncLiveRunJobExecutionException;
use App\Jobs\Connectors\SyncPreviewRunJob;
use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncLiveAdmissionService;
use App\Services\Sync\SyncPreviewAdmissionService;
use App\Services\Sync\SyncRunActiveRecoveryService;
use App\Services\Sync\UpdateSyncConfigurationInput;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Exceptions\SyncLiveAdmissionException;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
use App\Support\Sync\Preview\SyncPreviewConnectorCapabilityResolver;
use App\Support\Sync\SyncRuntimeTiming;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;
use ValueError;

class Stage3ALiveSafetyFoundationTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview],
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Live],
        ]);
    }

    #[Test]
    public function timing_config_enforces_live_timeout_plus_inflight_below_connector_retry_after(): void
    {
        $timing = app(SyncRuntimeTiming::class);

        $this->assertGreaterThan(0, $timing->liveJobTimeoutSeconds());
        $this->assertGreaterThan(0, $timing->maxInflightExternalRequestSeconds());
        $this->assertGreaterThan(0, $timing->queuedUndispatchedGraceSeconds());
        $this->assertLessThan(
            (int) config('queue.connections.database_connectors.retry_after'),
            $timing->liveJobTimeoutSeconds() + $timing->maxInflightExternalRequestSeconds(),
        );
    }

    #[Test]
    public function preview_admission_writes_queued_abandon_after_and_dispatch_confirmation(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);
        $actor = $this->grantPreviewPermission($account->workspace);

        $run = app(SyncPreviewAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );

        $run->refresh();

        $this->assertNotNull($run->queued_abandon_after);
        $this->assertNotNull($run->queue_dispatch_confirmed_at);
    }

    #[Test]
    public function unconfirmed_queued_run_recovers_after_grace(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Queued,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'queued_abandon_after' => now()->subSecond(),
        ]);

        app(SyncRunActiveRecoveryService::class)->recoverStaleActiveRuns($configuration->id);

        $run->refresh();
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertNotNull($run->completed_at);
    }

    #[Test]
    public function confirmed_queued_run_is_not_age_recovered(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Queued,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'queued_abandon_after' => now()->subHour(),
            'queue_dispatch_confirmed_at' => now()->subHour(),
        ]);

        app(SyncRunActiveRecoveryService::class)->recoverStaleActiveRuns($configuration->id);

        $this->assertSame(SyncRunStatus::Queued, $run->fresh()->status);
    }

    #[Test]
    public function late_queued_job_no_ops_after_recovery(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Failed,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'completed_at' => now(),
        ]);

        (new SyncPreviewRunJob($account->workspace_id, $account->id, $run->id))->handle(
            app(ProductExecutionAggregateBuilder::class),
            app(SyncPreviewConnectorCapabilityResolver::class),
            app(SyncRuntimeTiming::class),
        );

        $this->assertSame(SyncRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(0, SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->count());
    }

    #[Test]
    public function reservation_writes_lease_timestamps_that_remain_immutable(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);
        $actor = $this->grantPreviewPermission($account->workspace);

        $run = app(SyncPreviewAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );

        (new SyncPreviewRunJob($account->workspace_id, $account->id, $run->id))->handle(
            app(ProductExecutionAggregateBuilder::class),
            app(SyncPreviewConnectorCapabilityResolver::class),
            app(SyncRuntimeTiming::class),
        );

        $run = $run->fresh();
        $originalStartedAt = $run->started_at?->toIso8601String();
        $originalWriterDeadline = $run->writer_deadline_at?->toIso8601String();
        $originalRecoverableAfter = $run->recoverable_after?->toIso8601String();

        config(['sync_runtime.live_job_timeout_seconds' => 30]);
        config(['sync_runtime.max_inflight_external_request_seconds' => 5]);

        app(SyncRunActiveRecoveryService::class)->recoverStaleActiveRuns($configuration->id);

        $run->refresh();
        $this->assertSame($originalStartedAt, $run->started_at?->toIso8601String());
        $this->assertSame($originalWriterDeadline, $run->writer_deadline_at?->toIso8601String());
        $this->assertSame($originalRecoverableAfter, $run->recoverable_after?->toIso8601String());
    }

    #[Test]
    public function expired_running_run_recovers_to_failed(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Running,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'started_at' => now()->subHours(2),
            'writer_deadline_at' => now()->subHour(),
            'recoverable_after' => now()->subSecond(),
        ]);

        app(SyncRunActiveRecoveryService::class)->recoverStaleActiveRuns($configuration->id);

        $run->refresh();
        $this->assertSame(SyncRunStatus::Failed, $run->status);
    }

    #[Test]
    public function null_legacy_running_run_cannot_auto_recover(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Running,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'started_at' => now()->subDays(3),
        ]);

        app(SyncRunActiveRecoveryService::class)->recoverStaleActiveRuns($configuration->id);

        $this->assertSame(SyncRunStatus::Running, $run->fresh()->status);
    }

    #[Test]
    public function recovered_stale_run_allows_next_preview_admission(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);
        $actor = $this->grantPreviewPermission($account->workspace);

        SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Running,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'started_at' => now()->subHours(2),
            'writer_deadline_at' => now()->subHour(),
            'recoverable_after' => now()->subSecond(),
        ]);

        app(SyncRunActiveRecoveryService::class)->recoverStaleActiveRuns($configuration->id);

        $run = app(SyncPreviewAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );

        $this->assertSame(SyncRunStatus::Queued, $run->status);
    }

    #[Test]
    public function active_preview_blocks_live_admission(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);
        $this->seedCompletedPreviewRun($account, $configuration);
        $previewActor = $this->grantPreviewPermission($account->workspace, 'Preview Runner A');
        $actor = $this->grantLivePermission($account->workspace);

        app(SyncPreviewAdmissionService::class)->admit(
            $previewActor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );

        $this->expectException(SyncLiveAdmissionException::class);

        app(SyncLiveAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );
    }

    #[Test]
    public function live_admission_requires_run_sync_live_permission(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);
        $this->seedCompletedPreviewRun($account, $configuration);
        $actor = $this->grantPreviewPermission($account->workspace);

        $this->expectException(SyncLiveAdmissionException::class);

        app(SyncLiveAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );
    }

    #[Test]
    public function adobe_production_live_support_remains_false(): void
    {
        $account = $this->createConnectorAccount();
        $resolver = app(ConnectorSyncSupportResolver::class);

        $this->assertFalse($resolver->supports(
            $account,
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
            SyncRunMode::Live,
        ));
    }

    #[Test]
    public function live_admission_rejects_without_current_revision_preview_evidence(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);
        $actor = $this->grantLivePermission($account->workspace);

        $this->expectException(SyncLiveAdmissionException::class);

        app(SyncLiveAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );
    }

    #[Test]
    public function live_admission_accepts_with_completed_current_revision_preview(): void
    {
        Bus::fake([SyncLiveRunJob::class]);

        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);
        $this->seedCompletedPreviewRun($account, $configuration);
        $actor = $this->grantLivePermission($account->workspace);

        $run = app(SyncLiveAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );

        $this->assertSame(SyncRunMode::Live, $run->mode);
        $this->assertSame(SyncRunStatus::Queued, $run->status);
        Bus::assertDispatched(SyncLiveRunJob::class);
    }

    #[Test]
    public function live_shell_fails_closed_without_items_or_completion(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->preparePreviewReadyConfiguration($account);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Live,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Queued,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
        ]);

        try {
            (new SyncLiveRunJob($account->workspace_id, $account->id, $run->id))->handle(
                app(SyncRuntimeTiming::class),
            );
            $this->fail('Expected live shell failure.');
        } catch (SyncLiveRunJobExecutionException) {
            // expected
        }

        $run->refresh();
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->writer_deadline_at);
        $this->assertNotNull($run->recoverable_after);
        $this->assertSame(0, SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->count());
    }

    #[Test]
    public function live_job_uses_connector_lane_and_timeout(): void
    {
        $job = new SyncLiveRunJob('ws', 'acc', 'run');

        $this->assertSame(1, $job->tries);
        $this->assertSame(900, $job->timeout);
        $this->assertInstanceOf(Interruptible::class, $job);
    }

    #[Test]
    public function preview_outcomes_round_trip_through_preview_outcome_accessor(): void
    {
        foreach ([SyncPreviewOutcome::Ready, SyncPreviewOutcome::Warning, SyncPreviewOutcome::Blocked] as $outcome) {
            $item = new SyncRunItem(['outcome' => $outcome->value]);
            $this->assertSame($outcome, $item->previewOutcome());
        }
    }

    #[Test]
    public function live_outcomes_round_trip_through_live_outcome_accessor(): void
    {
        foreach ([SyncLiveOutcome::Synchronized, SyncLiveOutcome::NotApplied, SyncLiveOutcome::Partial, SyncLiveOutcome::Ambiguous] as $outcome) {
            $item = new SyncRunItem(['outcome' => $outcome->value]);
            $this->assertSame($outcome, $item->liveOutcome());
        }
    }

    #[Test]
    public function preview_ready_rejects_live_outcome_accessor(): void
    {
        $item = new SyncRunItem(['outcome' => SyncPreviewOutcome::Ready->value]);

        $this->expectException(ValueError::class);
        $item->liveOutcome();
    }

    #[Test]
    public function live_synchronized_rejects_preview_outcome_accessor(): void
    {
        $item = new SyncRunItem(['outcome' => SyncLiveOutcome::Synchronized->value]);

        $this->expectException(ValueError::class);
        $item->previewOutcome();
    }

    private function preparePreviewReadyConfiguration(ConnectorAccount $account): SyncConfiguration
    {
        $configuration = $this->createProductsSyncConfiguration($account);

        app(SyncConfigurationService::class)->update(
            $account,
            $configuration->id,
            new UpdateSyncConfigurationInput(
                enabledOperations: [SyncSemanticOperation::Export],
                operationalState: SyncConfigurationOperationalState::Enabled,
            ),
        );

        $configuration = app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => 4]),
        );

        $this->publishAuthoritativeSnapshot($account, ['name', 'sku']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productBinding('name')->id,
            'name',
        );

        return $configuration->refresh();
    }

    private function seedCompletedPreviewRun(ConnectorAccount $account, SyncConfiguration $configuration): SyncRun
    {
        Product::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'PREVIEW-'.Str::random(6),
            'name' => 'Preview evidence product',
            'is_active' => true,
        ]);

        $actor = $this->grantPreviewPermission($account->workspace);
        $run = app(SyncPreviewAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );

        (new SyncPreviewRunJob($account->workspace_id, $account->id, $run->id))->handle(
            app(ProductExecutionAggregateBuilder::class),
            app(SyncPreviewConnectorCapabilityResolver::class),
            app(SyncRuntimeTiming::class),
        );

        return $run->fresh();
    }

    private function grantPreviewPermission(Workspace $workspace, string $roleName = 'Preview Runner'): User
    {
        $actor = $this->createStaffUser(UserRole::Manager);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            $roleName.' '.Str::random(4),
            [WorkspacePermissions::RUN_SYNC_PREVIEW],
        );
        $this->assignRoleToMembership($membership, $role);

        return $actor;
    }

    private function grantLivePermission(Workspace $workspace): User
    {
        $actor = $this->createStaffUser(UserRole::Manager);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Live Runner '.Str::random(4),
            [WorkspacePermissions::RUN_SYNC_LIVE],
        );
        $this->assignRoleToMembership($membership, $role);

        return $actor;
    }

    private function createSyncSupportAccount(): ConnectorAccount
    {
        return $this->createConnectorAccount(null, ['auth_profile' => 'test_sync_support']);
    }
}
