<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Jobs\Connectors\ConnectorConnectionCheckJob;
use App\Models\ConnectorConnectionCheck;
use App\Services\Connectors\AdobePaaSConnectionCheckService;
use App\Services\Connectors\ConnectorConnectionCheckPersistence;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapability;
use App\Support\Connectors\ConnectorConnectionCheckResult;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ConnectorConnectionCheckJobAdvancedTest extends TestCase
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
    public function terminalizes_without_release_when_next_attempt_exceeds_retry_until(): void
    {
        $account = $this->createConnectorAccount();
        $row = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => 'manual',
            'status' => ConnectorConnectionCheckStatus::Running,
            'execution_attempts' => 1,
            'retry_until_at' => now()->addSeconds(20),
            'started_at' => now()->subMinute(),
        ]);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $result = ConnectorConnectionCheckResult::httpFailure(
            ConnectorConnectionCheckErrorCode::AdobeVendorUnavailable,
            503,
        );

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldReceive('checkConnection')->once()->andReturn($result);
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        $job = Mockery::mock(ConnectorConnectionCheckJob::class, [
            $account->workspace_id,
            $account->id,
            $row->id,
            $retryUntil,
        ])->makePartial();
        $job->shouldNotReceive('release');

        $job->handle(app(AdobePaaSConnectionCheckService::class), app(ConnectorConnectionCheckPersistence::class));

        $row->refresh();
        $this->assertSame(ConnectorConnectionCheckStatus::Failed, $row->status);
        $this->assertNull($row->next_attempt_at);
        $this->assertSame('adobe_vendor_unavailable', $row->error_code);
    }

    #[Test]
    public function exhausted_automatic_retry_updates_projection_to_temporarily_unavailable(): void
    {
        $account = $this->createConnectorAccount();
        $row = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => 'manual',
            'status' => ConnectorConnectionCheckStatus::Running,
            'execution_attempts' => 2,
            'retry_until_at' => now()->addMinutes(10),
            'started_at' => now()->subMinutes(2),
            'error_code' => 'adobe_vendor_unavailable',
            'cause_category' => 'vendor_unavailable',
            'actionability' => 'automatic_retry',
            'user_message_key' => 'connectors.errors.vendor_unavailable',
            'technical_summary' => 'HTTP 503 (adobe_vendor_unavailable)',
        ]);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $result = ConnectorConnectionCheckResult::httpFailure(
            ConnectorConnectionCheckErrorCode::AdobeVendorUnavailable,
            503,
        );

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldReceive('checkConnection')->once()->andReturn($result);
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        $job = new ConnectorConnectionCheckJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSConnectionCheckService::class), app(ConnectorConnectionCheckPersistence::class));

        $row->refresh();
        $account->refresh();

        $this->assertSame(ConnectorConnectionCheckStatus::Failed, $row->status);
        $this->assertSame(ConnectorAccountConnectionStatus::TemporarilyUnavailable, $account->connection_status);
        $this->assertSame('vendor_unavailable', $account->last_error_cause->value);
    }

    #[Test]
    public function stale_running_row_within_grace_is_not_recovered(): void
    {
        $account = $this->createConnectorAccount();
        $row = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => 'manual',
            'status' => ConnectorConnectionCheckStatus::Running,
            'execution_attempts' => 1,
            'retry_until_at' => now()->subSeconds(30),
            'started_at' => now()->subMinute(),
        ]);

        $persistence = app(ConnectorConnectionCheckPersistence::class);
        $this->assertFalse($persistence->isStale($row));
    }

    #[Test]
    public function stale_running_row_past_grace_is_recovered_with_vendor_precedence(): void
    {
        $account = $this->createConnectorAccount();
        $row = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => 'manual',
            'status' => ConnectorConnectionCheckStatus::Running,
            'execution_attempts' => 2,
            'retry_until_at' => now()->subMinutes(5),
            'started_at' => now()->subMinutes(6),
            'error_code' => 'adobe_rate_limited',
            'cause_category' => 'rate_limit',
            'actionability' => 'automatic_retry',
            'user_message_key' => 'connectors.errors.rate_limited',
            'technical_summary' => 'HTTP 429 (adobe_rate_limited)',
        ]);

        app(ConnectorConnectionCheckPersistence::class)->recoverStaleRowIfNeeded(
            $account->workspace_id,
            $account,
            $row,
        );

        $row->refresh();
        $account->refresh();

        $this->assertSame(ConnectorConnectionCheckStatus::Failed, $row->status);
        $this->assertSame('adobe_rate_limited', $row->error_code);
        $this->assertSame(ConnectorAccountConnectionStatus::TemporarilyUnavailable, $account->connection_status);
    }

    #[Test]
    public function without_overlapping_lock_expires_on_database_driver(): void
    {
        $lock = Cache::lock('connector-account:test-expire', 120);
        $lock->forceRelease();
        $lock->get();

        $this->travel(121)->seconds();

        $second = Cache::lock('connector-account:test-expire', 120);
        $this->assertTrue($second->get());
        $second->release();
    }

    #[Test]
    public function queue_alignment_values_match_committed_configuration(): void
    {
        $this->assertSame(90, (int) config('queue.connections.database.retry_after'));

        $compose = file_get_contents(base_path('docker-compose.yml'));
        $this->assertStringContainsString('php artisan queue:work --sleep=3 --tries=3 --max-time=3600', $compose);

        $dockerfile = file_get_contents(base_path('docker/php/Dockerfile'));
        $this->assertStringContainsString('pcntl', $dockerfile);
    }

    #[Test]
    public function duration_ms_uses_millisecond_conversion_not_nanoseconds(): void
    {
        $account = $this->createConnectorAccount();
        $row = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => 'manual',
            'status' => ConnectorConnectionCheckStatus::Queued,
            'execution_attempts' => 0,
            'retry_until_at' => now()->addMinutes(15),
            'started_at' => null,
        ]);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldReceive('checkConnection')->once()->andReturnUsing(function () {
            usleep(100_000);

            return ConnectorConnectionCheckResult::success();
        });
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        $job = new ConnectorConnectionCheckJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSConnectionCheckService::class), app(ConnectorConnectionCheckPersistence::class));

        $row->refresh();
        $this->assertGreaterThanOrEqual(50, $row->duration_ms);
        $this->assertLessThan(10_000, $row->duration_ms);
    }
}
