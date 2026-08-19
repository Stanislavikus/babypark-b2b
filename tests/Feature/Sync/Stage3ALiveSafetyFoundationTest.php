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
use App\Services\Sync\SyncRuntimeTimingResolver;
use App\Services\Sync\UpdateSyncConfigurationInput;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Exceptions\SyncLiveAdmissionException;
use App\Support\Sync\Exceptions\SyncPreviewAdmissionException;
use App\Support\Sync\Exceptions\SyncRuntimeTimingConfigurationException;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
use App\Support\Sync\Preview\SyncPreviewConnectorCapabilityResolver;
use App\Support\Sync\SyncRuntimeExecutionTiming;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

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
        $timing = app(SyncRuntimeTimingResolver::class)->resolveAdmissionTiming();

        $retryAfter = (int) config('queue.connections.database_connectors.retry_after');

        $this->assertGreaterThan(0, $timing->executionTiming->jobTimeoutSeconds);
        $this->assertGreaterThan(0, $timing->executionTiming->maxInflightExternalRequestSeconds);
        $this->assertGreaterThan(0, $timing->queuedUndispatchedGraceSeconds);
        $this->assertLessThan(
            $retryAfter,
            $timing->executionTiming->jobTimeoutSeconds + $timing->executionTiming->maxInflightExternalRequestSeconds,
        );
    }

    #[Test]
    public function unsafe_zero_live_timeout_rejects_live_admission(): void
    {
        Config::set('sync_runtime.live_job_timeout_seconds', 0);

        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->grantLivePermission($account->workspace);
        $this->seedCompletedPreview($account, $configuration);

        $this->expectException(SyncLiveAdmissionException::class);
        $this->expectExceptionMessage('unsafe');

        app(SyncLiveAdmissionService::class)->admit($actor, $account, $configuration->id);

        $this->assertSame(
            0,
            SyncRun::withoutWorkspaceScope()
                ->where('sync_configuration_id', $configuration->id)
                ->where('mode', SyncRunMode::Live)
                ->count(),
        );
    }

    #[Test]
    public function unsafe_zero_max_inflight_rejects_live_admission(): void
    {
        Config::set('sync_runtime.max_inflight_external_request_seconds', 0);

        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->grantLivePermission($account->workspace);
        $this->seedCompletedPreview($account, $configuration);

        $this->expectException(SyncLiveAdmissionException::class);
        $this->expectExceptionMessage('unsafe');

        app(SyncLiveAdmissionService::class)->admit($actor, $account, $configuration->id);
    }

    #[Test]
    public function unsafe_zero_queued_grace_rejects_live_admission(): void
    {
        Config::set('sync_runtime.queued_undispatched_grace_seconds', 0);

        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->grantLivePermission($account->workspace);
        $this->seedCompletedPreview($account, $configuration);

        $this->expectException(SyncLiveAdmissionException::class);
        $this->expectExceptionMessage('unsafe');

        app(SyncLiveAdmissionService::class)->admit($actor, $account, $configuration->id);
    }

    #[Test]
    public function unsafe_negative_queued_grace_rejects_live_admission(): void
    {
        Config::set('sync_runtime.queued_undispatched_grace_seconds', -5);

        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->grantLivePermission($account->workspace);
        $this->seedCompletedPreview($account, $configuration);

        $this->expectException(SyncLiveAdmissionException::class);
        $this->expectExceptionMessage('unsafe');

        app(SyncLiveAdmissionService::class)->admit($actor, $account, $configuration->id);
    }

    #[Test]
    public function resolver_rejects_execution_window_at_or_above_connector_retry_after(): void
    {
        $retryAfter = (int) config('queue.connections.database_connectors.retry_after');
        Config::set('sync_runtime.live_job_timeout_seconds', $retryAfter - 1);
        Config::set('sync_runtime.max_inflight_external_request_seconds', 1);

        $this->expectException(SyncRuntimeTimingConfigurationException::class);

        app(SyncRuntimeTimingResolver::class)->resolveAdmissionTiming();
    }

    #[Test]
    public function unsafe_execution_window_rejects_live_admission(): void
    {
        $retryAfter = (int) config('queue.connections.database_connectors.retry_after');
        Config::set('sync_runtime.live_job_timeout_seconds', $retryAfter - 1);
        Config::set('sync_runtime.max_inflight_external_request_seconds', 1);

        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->grantLivePermission($account->workspace);
        $this->seedCompletedPreview($account, $configuration);

        $this->expectException(SyncLiveAdmissionException::class);
        $this->expectExceptionMessage('unsafe');

        app(SyncLiveAdmissionService::class)->admit($actor, $account, $configuration->id);
    }

    #[Test]
    public function preview_admission_writes_queued_abandon_after_and_dispatch_confirmation(): void
    {
        Bus::fake();

        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->grantPreviewPermission($account->workspace);

        $run = app(SyncPreviewAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );

        $this->assertNotNull($run->queued_abandon_after);
        $this->assertNotNull($run->fresh()->queue_dispatch_confirmed_at);
        Bus::assertDispatched(SyncPreviewRunJob::class);
    }

    #[Test]
    public function preview_admission_terminalizes_queued_run_when_dispatch_fails(): void
    {
        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('dispatch failed'));

        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->grantPreviewPermission($account->workspace);

        try {
            app(SyncPreviewAdmissionService::class)->admit(
                $actor,
                $account,
                $configuration->id,
                SyncSemanticOperation::Export,
            );
            $this->fail('Expected dispatch failure.');
        } catch (SyncPreviewAdmissionException) {
            // expected
        }

        $run = SyncRun::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->sole();

        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertNotNull($run->completed_at);
    }

    #[Test]
    public function unconfirmed_queued_run_is_recovered_after_grace(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);

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
        $configuration = $this->prepareReadyConfiguration($account);

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
    public function late_old_queued_job_no_ops_after_recovery(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);

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
        );

        $this->assertSame(SyncRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(0, SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->count());
    }

    #[Test]
    public function preview_job_reservation_writes_immutable_lease_timestamps(): void
    {
        Config::set('sync_runtime.live_job_timeout_seconds', 900);
        Config::set('sync_runtime.max_inflight_external_request_seconds', 60);

        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Queued,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
        ]);

        $job = new SyncPreviewRunJob($account->workspace_id, $account->id, $run->id);

        Config::set('sync_runtime.live_job_timeout_seconds', 30);
        Config::set('sync_runtime.max_inflight_external_request_seconds', 5);

        try {
            $job->handle(
                app(ProductExecutionAggregateBuilder::class),
                app(SyncPreviewConnectorCapabilityResolver::class),
            );
        } catch (\Throwable) {
            // Preview execution may fail after reservation; lease timestamps are the proof target.
        }

        $run = $run->fresh();
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->writer_deadline_at);
        $this->assertNotNull($run->recoverable_after);
        $this->assertTrue($run->writer_deadline_at->equalTo($run->started_at->copy()->addSeconds(900)));
        $this->assertTrue($run->recoverable_after->equalTo($run->writer_deadline_at->copy()->addSeconds(60)));
        $this->assertTrue($run->recoverable_after->greaterThan(now()->addMinutes(10)));
    }

    #[Test]
    public function live_job_reservation_uses_snapshotted_timing_after_config_changes(): void
    {
        Config::set('sync_runtime.live_job_timeout_seconds', 900);
        Config::set('sync_runtime.max_inflight_external_request_seconds', 60);

        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);

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

        $job = new SyncLiveRunJob(
            $account->workspace_id,
            $account->id,
            $run->id,
            new SyncRuntimeExecutionTiming(900, 60),
        );

        Config::set('sync_runtime.live_job_timeout_seconds', 30);
        Config::set('sync_runtime.max_inflight_external_request_seconds', 5);

        try {
            $job->handle();
        } catch (SyncLiveRunJobExecutionException) {
            // Expected fail-closed shell after reservation.
        }

        $run = $run->fresh();
        $this->assertNotNull($run->started_at);
        $this->assertTrue($run->writer_deadline_at->equalTo($run->started_at->copy()->addSeconds(900)));
        $this->assertTrue($run->recoverable_after->equalTo($run->writer_deadline_at->copy()->addSeconds(60)));
    }

    #[Test]
    public function expired_running_run_recovers_to_failed(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Running,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'started_at' => now()->subHour(),
            'writer_deadline_at' => now()->subMinutes(30),
            'recoverable_after' => now()->subSecond(),
        ]);

        app(SyncRunActiveRecoveryService::class)->recoverStaleActiveRuns($configuration->id);

        $run->refresh();
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertNotNull($run->completed_at);
    }

    #[Test]
    public function null_legacy_running_run_cannot_auto_recover(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Running,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'started_at' => now()->subDays(2),
        ]);

        app(SyncRunActiveRecoveryService::class)->recoverStaleActiveRuns($configuration->id);

        $this->assertSame(SyncRunStatus::Running, $run->fresh()->status);
    }

    #[Test]
    public function recovered_stale_run_allows_next_preview_admission(): void
    {
        Bus::fake();

        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->grantPreviewPermission($account->workspace);

        SyncRun::withoutWorkspaceScope()->create([
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

        $run = app(SyncPreviewAdmissionService::class)->admit(
            $actor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );

        $this->assertSame(SyncRunStatus::Queued, $run->status);
        Bus::assertDispatched(SyncPreviewRunJob::class);
    }

    #[Test]
    public function active_preview_blocks_live_admission(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->grantLivePermission($account->workspace);
        $this->seedCompletedPreview($account, $configuration);

        SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Running,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'recoverable_after' => now()->addHour(),
        ]);

        $this->expectException(SyncLiveAdmissionException::class);

        app(SyncLiveAdmissionService::class)->admit($actor, $account, $configuration->id);
    }

    #[Test]
    public function live_admission_requires_run_sync_live_permission(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->grantPreviewPermission($account->workspace);
        $this->seedCompletedPreview($account, $configuration);

        $this->expectException(SyncLiveAdmissionException::class);

        app(SyncLiveAdmissionService::class)->admit($actor, $account, $configuration->id);
    }

    #[Test]
    public function adobe_production_live_support_remains_false(): void
    {
        app()->forgetInstance(ConnectorProfileRegistry::class);

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
    public function live_admission_requires_completed_current_revision_preview(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->grantLivePermission($account->workspace);

        $this->expectException(SyncLiveAdmissionException::class);

        app(SyncLiveAdmissionService::class)->admit($actor, $account, $configuration->id);
    }

    #[Test]
    public function live_admission_accepts_test_profile_with_preview_evidence(): void
    {
        Bus::fake();

        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->grantLivePermission($account->workspace);
        $this->seedCompletedPreview($account, $configuration);

        $run = app(SyncLiveAdmissionService::class)->admit($actor, $account, $configuration->id);

        $this->assertSame(SyncRunMode::Live, $run->mode);
        $this->assertSame(SyncRunStatus::Queued, $run->status);
        Bus::assertDispatched(SyncLiveRunJob::class);
    }

    #[Test]
    public function live_job_shell_fails_closed_without_items(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->prepareReadyConfiguration($account);

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
            (new SyncLiveRunJob($account->workspace_id, $account->id, $run->id))->handle();
            $this->fail('Expected live shell failure.');
        } catch (SyncLiveRunJobExecutionException) {
            // expected
        }

        $run = $run->fresh();
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertNotNull($run->writer_deadline_at);
        $this->assertNotNull($run->recoverable_after);
        $this->assertSame(0, SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->count());
    }

    #[Test]
    public function preview_job_uses_snapshotted_job_timeout(): void
    {
        Config::set('sync_runtime.live_job_timeout_seconds', 900);
        Config::set('sync_runtime.max_inflight_external_request_seconds', 60);

        $job = new SyncPreviewRunJob('ws', 'acc', 'run');

        $this->assertSame(900, $job->timeout);
    }

    #[Test]
    public function live_job_uses_connector_lane_with_single_try_and_timeout(): void
    {
        $job = new SyncLiveRunJob('ws', 'acc', 'run');

        $this->assertSame(1, $job->tries);
        $this->assertSame(900, $job->timeout);
        $this->assertTrue(is_subclass_of(SyncLiveRunJob::class, Interruptible::class));
    }

    #[Test]
    public function preview_outcomes_round_trip_through_preview_outcome_accessor(): void
    {
        $item = new SyncRunItem(['outcome' => SyncPreviewOutcome::Ready->value]);
        $this->assertSame(SyncPreviewOutcome::Ready, $item->previewOutcome());

        $item->outcome = SyncPreviewOutcome::Warning->value;
        $this->assertSame(SyncPreviewOutcome::Warning, $item->previewOutcome());
    }

    #[Test]
    public function live_outcomes_round_trip_through_live_outcome_accessor(): void
    {
        $item = new SyncRunItem(['outcome' => SyncLiveOutcome::Synchronized->value]);
        $this->assertSame(SyncLiveOutcome::Synchronized, $item->liveOutcome());
    }

    #[Test]
    public function ready_preview_outcome_rejects_live_outcome_accessor(): void
    {
        $item = new SyncRunItem(['outcome' => SyncPreviewOutcome::Ready->value]);

        $this->expectException(InvalidArgumentException::class);
        $item->liveOutcome();
    }

    #[Test]
    public function synchronized_live_outcome_rejects_preview_outcome_accessor(): void
    {
        $item = new SyncRunItem(['outcome' => SyncLiveOutcome::Synchronized->value]);

        $this->expectException(InvalidArgumentException::class);
        $item->previewOutcome();
    }

    private function prepareReadyConfiguration(ConnectorAccount $account): SyncConfiguration
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

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productVariantBinding('sku')->id,
            'sku',
        );

        return $configuration->refresh();
    }

    private function seedCompletedPreview(ConnectorAccount $account, SyncConfiguration $configuration): void
    {
        SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'completed_at' => now(),
        ]);
    }

    private function grantPreviewPermission(Workspace $workspace): User
    {
        $actor = $this->createStaffUser(UserRole::Manager);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Preview Runner',
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
            'Live Runner',
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
