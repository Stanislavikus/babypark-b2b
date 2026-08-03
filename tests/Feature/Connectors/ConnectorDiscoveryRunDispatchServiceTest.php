<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\UserRole;
use App\Jobs\Connectors\ConnectorDiscoveryRunJob;
use App\Models\ConnectorDiscoveryRun;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorDiscoveryRunDispatchService;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\Exceptions\ConnectorAccountNotFoundException;
use App\Support\Connectors\Exceptions\ConnectorDiscoveryManualTriggerDisabledException;
use App\Support\Connectors\Exceptions\UnsupportedConnectorCapabilityException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\EnablesConnectorSchemaDiscoveryCapability;
use Tests\TestCase;

class ConnectorDiscoveryRunDispatchServiceTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use EnablesConnectorSchemaDiscoveryCapability;
    use RefreshDatabase;

    private ConnectorDiscoveryRunDispatchService $dispatchService;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);

        $this->enableSchemaDiscoveryCapability();
        config(['connectors.discovery.manual_trigger_enabled' => true]);

        $this->workspace = $this->defaultWorkspace();
        $this->dispatchService = app(ConnectorDiscoveryRunDispatchService::class);
    }

    #[Test]
    public function execute_manual_creates_row_and_dispatches_job(): void
    {
        Queue::fake();
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);

        $decision = $this->dispatchService->executeManual($admin, $this->workspace->id, $account->id);

        $this->assertTrue($decision->shouldDispatch);
        $this->assertNotNull($decision->retryUntilTimestamp);

        $row = ConnectorDiscoveryRun::withoutWorkspaceScope()->findOrFail($decision->discoveryRunId);
        $this->assertSame(ConnectorDiscoveryRunStatus::Queued, $row->status);
        $this->assertNull($row->started_at);
        $this->assertSame(0, $row->execution_attempts);
        $this->assertNotNull($row->retry_until_at);
        $this->assertNotNull($row->connector_schema_source_id);

        Queue::assertPushed(ConnectorDiscoveryRunJob::class, function (ConnectorDiscoveryRunJob $job) use ($row, $decision): bool {
            return $row->retry_until_at->getTimestamp() === $job->retryUntil()->getTimestamp()
                && $decision->retryUntilTimestamp === $job->retryUntil()->getTimestamp();
        });
    }

    #[Test]
    public function second_dispatch_returns_same_row_and_does_not_push_second_job(): void
    {
        Queue::fake();
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);

        $firstDecision = $this->dispatchService->executeManual($admin, $this->workspace->id, $account->id);
        $secondDecision = $this->dispatchService->executeManual($admin, $this->workspace->id, $account->id);

        $this->assertSame($firstDecision->discoveryRunId, $secondDecision->discoveryRunId);
        $this->assertTrue($firstDecision->shouldDispatch);
        $this->assertFalse($secondDecision->shouldDispatch);
        $this->assertNull($secondDecision->retryUntilTimestamp);
        $this->assertSame(1, ConnectorDiscoveryRun::withoutWorkspaceScope()->count());
        Queue::assertPushed(ConnectorDiscoveryRunJob::class, 1);
    }

    #[Test]
    public function disabled_account_is_rejected_by_policy_before_dispatch(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace, ['is_enabled' => false]);

        $this->expectException(AuthorizationException::class);
        $this->dispatchService->executeManual($admin, $this->workspace->id, $account->id);
    }

    #[Test]
    public function manual_trigger_disabled_throws_before_row_creation(): void
    {
        config(['connectors.discovery.manual_trigger_enabled' => false]);

        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);

        try {
            $this->dispatchService->executeManual($admin, $this->workspace->id, $account->id);
            $this->fail('Expected ConnectorDiscoveryManualTriggerDisabledException');
        } catch (ConnectorDiscoveryManualTriggerDisabledException) {
            $this->assertSame(0, ConnectorDiscoveryRun::withoutWorkspaceScope()->count());
        }
    }

    #[Test]
    public function missing_capability_throws_before_row_creation(): void
    {
        config(['connectors.profiles.adobe_commerce_paas_oauth1_integration.capabilities' => []]);
        $this->app->forgetInstance(ConnectorProfileRegistry::class);
        $this->dispatchService = app(ConnectorDiscoveryRunDispatchService::class);

        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);

        try {
            $this->dispatchService->executeManual($admin, $this->workspace->id, $account->id);
            $this->fail('Expected UnsupportedConnectorCapabilityException');
        } catch (UnsupportedConnectorCapabilityException) {
            $this->assertSame(0, ConnectorDiscoveryRun::withoutWorkspaceScope()->count());
        }
    }

    #[Test]
    public function authorization_uses_explicit_actor_not_ambient_user(): void
    {
        Queue::fake();

        $allowedActor = $this->createStaffUser(UserRole::Manager);
        $allowedActor->givePermissionTo(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS);

        $deniedActor = $this->createStaffUser(UserRole::Manager);
        $account = $this->createConnectorAccount($this->workspace);

        $this->actingAs($deniedActor);
        $this->dispatchService->executeManual($allowedActor, $this->workspace->id, $account->id);

        $this->actingAs($allowedActor);
        $this->expectException(AuthorizationException::class);
        $this->dispatchService->executeManual($deniedActor, $this->workspace->id, $account->id);
    }

    #[Test]
    public function unknown_account_throws_not_found(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);

        $this->expectException(ConnectorAccountNotFoundException::class);
        $this->dispatchService->executeManual($admin, $this->workspace->id, '00000000-0000-4000-8000-000000000001');
    }

    #[Test]
    public function queued_status_label_key_exists(): void
    {
        $this->assertSame(
            'connectors.enums.discovery_run_status.queued',
            ConnectorDiscoveryRunStatus::Queued->label(),
        );
    }

    #[Test]
    public function dispatch_failure_compensates_row_to_failed(): void
    {
        $this->mock(Dispatcher::class, function ($mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andThrow(new \RuntimeException('queue dispatch failed'));
        });

        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);

        $decision = $this->dispatchService->executeManual($admin, $this->workspace->id, $account->id);

        $this->assertTrue($decision->shouldDispatch);
        $this->assertNotNull($decision->retryUntilTimestamp);

        $row = ConnectorDiscoveryRun::withoutWorkspaceScope()->findOrFail($decision->discoveryRunId);
        $this->assertSame(ConnectorDiscoveryRunStatus::Failed, $row->status);
        $this->assertSame('discovery_dispatch_failed', $row->error_code);
    }

    #[Test]
    public function stale_queued_row_is_recovered_and_new_dispatch_is_allowed(): void
    {
        Queue::fake();
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);
        $source = app(ConnectorDiscoverySourceResolver::class)->resolve($account);

        $stale = ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => 'manual',
            'status' => ConnectorDiscoveryRunStatus::Queued,
            'execution_attempts' => 0,
            'retry_until_at' => now()->subMinute(),
            'started_at' => null,
        ]);

        $decision = $this->dispatchService->executeManual($admin, $this->workspace->id, $account->id);

        $stale->refresh();
        $this->assertSame(ConnectorDiscoveryRunStatus::Failed, $stale->status);
        $this->assertSame('discovery_dispatch_failed', $stale->error_code);
        $this->assertNotSame($stale->id, $decision->discoveryRunId);
        $this->assertTrue($decision->shouldDispatch);
        Queue::assertPushed(ConnectorDiscoveryRunJob::class, 1);
    }
}
