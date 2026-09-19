<?php

namespace Tests\Feature\Connectors;

use App\Enums\UserRole;
use App\Jobs\Connectors\ConnectorConnectionCheckJob;
use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\EnablesConnectorConnectionCheckCapability;
use Tests\TestCase;

class ConnectorQueueRuntimeAlignmentTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use EnablesConnectorConnectionCheckCapability;
    use RefreshDatabase;

    private const DISCOVERY_JOB_TIMEOUT_SECONDS = 900;

    private const DISCOVERY_LOCK_EXPIRE_AFTER_SECONDS = 1100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->enableConnectionCheckCapability();
    }

    #[Test]
    public function database_connectors_connection_is_configured_for_the_connectors_lane(): void
    {
        $connection = config('queue.connections.database_connectors');

        $this->assertIsArray($connection);
        $this->assertSame('database', $connection['driver']);
        $this->assertSame('connectors', $connection['queue']);
        $this->assertSame(1200, (int) $connection['retry_after']);
        $this->assertSame(env('DB_QUEUE_TABLE', 'jobs'), $connection['table']);
    }

    #[Test]
    public function discovery_lane_timeout_values_are_internally_consistent(): void
    {
        $retryAfter = (int) config('queue.connections.database_connectors.retry_after');

        $this->assertGreaterThan(self::DISCOVERY_LOCK_EXPIRE_AFTER_SECONDS, $retryAfter);
        $this->assertGreaterThan(self::DISCOVERY_JOB_TIMEOUT_SECONDS, self::DISCOVERY_LOCK_EXPIRE_AFTER_SECONDS);
        $this->assertGreaterThan(self::DISCOVERY_JOB_TIMEOUT_SECONDS, $retryAfter);
    }

    #[Test]
    public function intended_production_default_database_queue_defaults_remain_configured(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('QUEUE_CONNECTION=database', $envExample);
        $this->assertSame('default', config('queue.connections.database.queue'));
        $this->assertSame(90, (int) config('queue.connections.database.retry_after'));
    }

    #[Test]
    public function connection_check_dispatch_does_not_override_configured_default_connection_or_queue(): void
    {
        Queue::fake();

        $workspace = $this->defaultWorkspace();
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount($workspace);

        app(ConnectorConnectionCheckDispatchService::class)->executeManual(
            $admin,
            $workspace->id,
            $account->id,
        );

        Queue::assertPushed(
            ConnectorConnectionCheckJob::class,
            function (ConnectorConnectionCheckJob $job, ?string $queue): bool {
                $this->assertNull($job->connection);
                $this->assertNull($job->queue);
                $this->assertNull($queue);

                return true;
            }
        );
    }

    #[Test]
    public function connection_check_without_overlapping_keeps_original_lock_timing(): void
    {
        $job = new ConnectorConnectionCheckJob(
            'workspace-id',
            'connector-account-id',
            'connection-check-id',
            now()->addMinutes(15)->getTimestamp(),
        );

        $middleware = $job->middleware()[0];
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware);
        $this->assertSame(120, $middleware->expiresAfter);
        $this->assertSame(30, $middleware->releaseAfter);
        $this->assertTrue($middleware->shareKey);
        $this->assertSame('connector-account:connector-account-id', $middleware->key);
    }

    #[Test]
    public function docker_compose_includes_dedicated_connector_queue_worker(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        $this->assertStringContainsString('connector-queue:', $compose);
        $this->assertStringContainsString(
            'php artisan queue:work database_connectors --queue=connectors --sleep=3 --tries=3 --timeout=900 --max-time=3600',
            $compose
        );
    }
}
