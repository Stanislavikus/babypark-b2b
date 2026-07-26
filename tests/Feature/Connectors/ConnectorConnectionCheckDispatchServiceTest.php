<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorConnectionCheckLifecycleErrorCode;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\UserRole;
use App\Jobs\Connectors\ConnectorConnectionCheckJob;
use App\Models\ConnectorConnectionCheck;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use App\Services\Connectors\ConnectorConnectionCheckPersistence;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\Exceptions\ConnectorAccountDisabledException;
use App\Support\Connectors\Exceptions\ConnectorAccountNotFoundException;
use App\Support\Connectors\Exceptions\UnsupportedConnectorCapabilityException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\EnablesConnectorConnectionCheckCapability;
use Tests\TestCase;

class ConnectorConnectionCheckDispatchServiceTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use EnablesConnectorConnectionCheckCapability;
    use RefreshDatabase;

    private ConnectorConnectionCheckDispatchService $dispatchService;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);

        $this->enableConnectionCheckCapability();
        $this->workspace = $this->defaultWorkspace();
        $this->dispatchService = app(ConnectorConnectionCheckDispatchService::class);
    }

    #[Test]
    public function execute_manual_creates_row_and_dispatches_job(): void
    {
        Queue::fake();
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);

        $checkId = $this->dispatchService->executeManual($admin, $this->workspace->id, $account->id);

        $row = ConnectorConnectionCheck::withoutWorkspaceScope()->findOrFail($checkId);
        $this->assertSame(ConnectorConnectionCheckStatus::Queued, $row->status);
        $this->assertNull($row->started_at);
        $this->assertSame(0, $row->execution_attempts);
        $this->assertNotNull($row->retry_until_at);

        Queue::assertPushed(ConnectorConnectionCheckJob::class, function (ConnectorConnectionCheckJob $job) use ($row): bool {
            return $row->retry_until_at->getTimestamp() === $job->retryUntil()->getTimestamp();
        });
    }

    #[Test]
    public function second_dispatch_returns_same_row_and_does_not_push_second_job(): void
    {
        Queue::fake();
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);

        $firstId = $this->dispatchService->executeManual($admin, $this->workspace->id, $account->id);
        $secondId = $this->dispatchService->executeManual($admin, $this->workspace->id, $account->id);

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, ConnectorConnectionCheck::withoutWorkspaceScope()->count());
        Queue::assertPushed(ConnectorConnectionCheckJob::class, 1);
    }

    #[Test]
    public function disabled_account_is_rejected_before_capability_gate(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace, ['is_enabled' => false]);

        $this->expectException(ConnectorAccountDisabledException::class);
        $this->dispatchService->executeManual($admin, $this->workspace->id, $account->id);
    }

    #[Test]
    public function missing_capability_throws_before_row_creation(): void
    {
        config(['connectors.profiles.adobe_commerce_paas_oauth1_integration.capabilities' => []]);
        $this->app->forgetInstance(ConnectorProfileRegistry::class);
        $this->dispatchService = app(ConnectorConnectionCheckDispatchService::class);

        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);

        try {
            $this->dispatchService->executeManual($admin, $this->workspace->id, $account->id);
            $this->fail('Expected UnsupportedConnectorCapabilityException');
        } catch (UnsupportedConnectorCapabilityException) {
            $this->assertSame(0, ConnectorConnectionCheck::withoutWorkspaceScope()->count());
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
            'connectors.enums.connection_check_status.queued',
            ConnectorConnectionCheckStatus::Queued->label(),
        );
    }

    #[Test]
    public function dispatch_failure_compensates_row_to_failed(): void
    {
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);
        $row = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => 'manual',
            'status' => ConnectorConnectionCheckStatus::Queued,
            'execution_attempts' => 0,
            'retry_until_at' => now()->addMinutes(15),
            'started_at' => null,
        ]);

        app(ConnectorConnectionCheckPersistence::class)->writeLifecycleFailure(
            $account->workspace_id,
            $account->id,
            $row->id,
            ConnectorConnectionCheckLifecycleErrorCode::DispatchFailed,
        );

        $row->refresh();
        $this->assertSame(ConnectorConnectionCheckStatus::Failed, $row->status);
        $this->assertSame('connection_check_dispatch_failed', $row->error_code);
    }

    #[Test]
    public function stale_queued_row_is_recovered_and_new_dispatch_is_allowed(): void
    {
        Queue::fake();
        $admin = $this->createStaffUser(UserRole::Admin);
        $account = $this->createConnectorAccount($this->workspace);

        $stale = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => 'manual',
            'status' => ConnectorConnectionCheckStatus::Queued,
            'execution_attempts' => 0,
            'retry_until_at' => now()->subMinute(),
            'started_at' => null,
        ]);

        $newId = $this->dispatchService->executeManual($admin, $this->workspace->id, $account->id);

        $stale->refresh();
        $this->assertSame(ConnectorConnectionCheckStatus::Failed, $stale->status);
        $this->assertSame('connection_check_dispatch_failed', $stale->error_code);
        $this->assertNotSame($stale->id, $newId);
        Queue::assertPushed(ConnectorConnectionCheckJob::class, 1);
    }
}
