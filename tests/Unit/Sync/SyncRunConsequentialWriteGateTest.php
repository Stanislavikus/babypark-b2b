<?php

namespace Tests\Unit\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Models\SyncRun;
use App\Support\Sync\Live\SyncRunConsequentialWriteGate;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;

class SyncRunConsequentialWriteGateTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seedFieldDefinitions();
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import],
            [SyncDataDomain::Products, SyncSemanticOperation::Export],
        ]);
    }

    #[Test]
    public function gate_reads_fresh_persisted_status_after_construction(): void
    {
        [$run, $gate] = $this->runningGateWithFutureDeadline();

        $this->assertTrue($gate->permitsConsequentialWrite());

        SyncRun::withoutWorkspaceScope()
            ->where('workspace_id', $run->workspace_id)
            ->where('id', $run->id)
            ->update(['status' => SyncRunStatus::Failed]);

        $this->assertFalse($gate->permitsConsequentialWrite());
        $this->assertFalse($gate->permitsProductExecution());
    }

    #[Test]
    public function missing_run_denies_consequential_write(): void
    {
        $gate = new SyncRunConsequentialWriteGate(
            (string) Str::uuid(),
            (string) Str::uuid(),
        );

        $this->assertFalse($gate->permitsConsequentialWrite());
    }

    #[Test]
    public function wrong_workspace_denies_consequential_write(): void
    {
        [$run, $gate] = $this->runningGateWithFutureDeadline(
            workspaceId: (string) Str::uuid(),
        );

        $this->assertFalse($gate->permitsConsequentialWrite());
        $this->assertSame(SyncRunStatus::Running, $run->fresh()->status);
    }

    #[Test]
    public function expired_writer_deadline_denies_consequential_write(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Live,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Running,
            'started_at' => now()->subHour(),
            'writer_deadline_at' => now()->subMinute(),
            'recoverable_after' => now()->addMinutes(30),
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
        ]);

        $gate = new SyncRunConsequentialWriteGate($run->workspace_id, $run->id);

        $this->assertFalse($gate->permitsConsequentialWrite());
    }

    #[Test]
    public function null_writer_deadline_denies_consequential_write(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Live,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Running,
            'started_at' => now(),
            'writer_deadline_at' => null,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
        ]);

        $gate = new SyncRunConsequentialWriteGate($run->workspace_id, $run->id);

        $this->assertFalse($gate->permitsConsequentialWrite());
    }

    /**
     * @return array{0: SyncRun, 1: SyncRunConsequentialWriteGate}
     */
    private function runningGateWithFutureDeadline(?string $workspaceId = null): array
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Live,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Running,
            'started_at' => now(),
            'writer_deadline_at' => now()->addMinutes(15),
            'recoverable_after' => now()->addMinutes(16),
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
        ]);

        $gate = new SyncRunConsequentialWriteGate(
            $workspaceId ?? $run->workspace_id,
            $run->id,
        );

        return [$run, $gate];
    }
}
