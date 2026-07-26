<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Enums\ConnectorConnectionCheckLifecycleErrorCode;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Jobs\Connectors\ConnectorConnectionCheckJob;
use App\Jobs\Connectors\ConnectorConnectionCheckJobExecutionException;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Services\Connectors\AdobePaaSConnectionCheckService;
use App\Services\Connectors\ConnectorConnectionCheckPersistence;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapability;
use App\Support\Connectors\ConnectorConnectionCheckResult;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ConnectorConnectionCheckJobTest extends TestCase
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
    public function job_uses_retry_until_and_max_exceptions(): void
    {
        $retryUntil = now()->addMinutes(15)->getTimestamp();
        $job = new ConnectorConnectionCheckJob('ws', 'acct', 'check', $retryUntil);

        $this->assertSame($retryUntil, $job->retryUntil()->getTimestamp());
        $this->assertSame(1, $job->maxExceptions);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame(45, $job->timeout);
    }

    #[Test]
    public function without_overlapping_middleware_is_configured(): void
    {
        $job = new ConnectorConnectionCheckJob('ws', 'acct', 'check', now()->addMinutes(15)->getTimestamp());
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    #[Test]
    public function success_terminalizes_and_updates_projection(): void
    {
        $account = $this->createConnectorAccount($this->defaultWorkspace(), [
            'connection_status' => ConnectorAccountConnectionStatus::Untested,
            'last_error_cause' => ConnectorErrorCause::Authentication,
            'last_error_actionability' => ConnectorErrorActionability::UserActionRequired,
            'last_error_message_key' => 'connectors.errors.invalid_credentials',
            'last_error_at' => now()->subDay(),
        ]);

        $row = $this->createQueuedRow($account);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $service = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $service->shouldReceive('checkConnection')->once()->andReturn(ConnectorConnectionCheckResult::success());

        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $service);

        $job = new ConnectorConnectionCheckJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSConnectionCheckService::class), app(ConnectorConnectionCheckPersistence::class));

        $row->refresh();
        $account->refresh();

        $this->assertSame(ConnectorConnectionCheckStatus::Succeeded, $row->status);
        $this->assertNotNull($row->finished_at);
        $this->assertSame(ConnectorAccountConnectionStatus::Connected, $account->connection_status);
        $this->assertNull($account->last_error_cause);
        $this->assertNull($account->last_error_actionability);
        $this->assertNull($account->last_error_message_key);
        $this->assertNull($account->last_error_at);
        $this->assertNotNull($account->last_successful_check_at);
    }

    #[Test]
    public function non_retryable_failure_writes_last_error_fields(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $result = ConnectorConnectionCheckResult::httpFailure(
            ConnectorConnectionCheckErrorCode::AdobeInvalidCredentials,
            401,
        );

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldReceive('checkConnection')->once()->andReturn($result);

        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        $job = new ConnectorConnectionCheckJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSConnectionCheckService::class), app(ConnectorConnectionCheckPersistence::class));

        $row->refresh();
        $account->refresh();

        $this->assertSame(ConnectorConnectionCheckStatus::Failed, $row->status);
        $this->assertSame(ConnectorAccountConnectionStatus::AttentionRequired, $account->connection_status);
        $this->assertSame(ConnectorErrorCause::Authentication, $account->last_error_cause);
        $this->assertSame(ConnectorErrorActionability::UserActionRequired, $account->last_error_actionability);
        $this->assertSame('connectors.errors.invalid_credentials', $account->last_error_message_key);
        $this->assertNotNull($account->last_error_at);
    }

    #[Test]
    public function intermediate_retry_persists_classification_without_terminalizing(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);
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
        $job->shouldReceive('release')->once()->with(30);

        $job->handle(app(AdobePaaSConnectionCheckService::class), app(ConnectorConnectionCheckPersistence::class));

        $row->refresh();
        $this->assertSame(ConnectorConnectionCheckStatus::Running, $row->status);
        $this->assertSame('adobe_vendor_unavailable', $row->error_code);
        $this->assertNotNull($row->next_attempt_at);
        $this->assertNull($row->finished_at);
    }

    #[Test]
    public function account_disabled_before_execution_writes_lifecycle_outcome(): void
    {
        $account = $this->createConnectorAccount($this->defaultWorkspace(), ['is_enabled' => false, 'connection_status' => ConnectorAccountConnectionStatus::Disabled]);
        $row = $this->createQueuedRow($account);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldNotReceive('checkConnection');
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        $job = new ConnectorConnectionCheckJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSConnectionCheckService::class), app(ConnectorConnectionCheckPersistence::class));

        $row->refresh();
        $account->refresh();

        $this->assertSame(ConnectorConnectionCheckStatus::Failed, $row->status);
        $this->assertSame('connection_check_account_disabled_before_execution', $row->error_code);
        $this->assertSame(ConnectorErrorCause::Configuration, $row->cause_category);
        $this->assertSame(ConnectorErrorActionability::WorkspaceAdminRequired, $row->actionability);
        $this->assertSame(ConnectorAccountConnectionStatus::Disabled, $account->connection_status);
    }

    #[Test]
    public function next_attempt_at_blocks_early_redelivery(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account, [
            'status' => ConnectorConnectionCheckStatus::Running,
            'execution_attempts' => 1,
            'started_at' => now(),
            'error_code' => 'adobe_rate_limited',
            'cause_category' => 'rate_limit',
            'actionability' => 'automatic_retry',
            'user_message_key' => 'connectors.errors.rate_limited',
            'technical_summary' => 'HTTP 429 (adobe_rate_limited)',
            'next_attempt_at' => now()->addMinutes(5),
        ]);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldNotReceive('checkConnection');
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        $job = Mockery::mock(ConnectorConnectionCheckJob::class, [
            $account->workspace_id,
            $account->id,
            $row->id,
            $retryUntil,
        ])->makePartial();
        $job->shouldReceive('release')->once();

        $job->handle(app(AdobePaaSConnectionCheckService::class), app(ConnectorConnectionCheckPersistence::class));

        $row->refresh();
        $this->assertSame(1, $row->execution_attempts);
    }

    #[Test]
    public function failed_method_preserves_vendor_classification(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account, [
            'status' => ConnectorConnectionCheckStatus::Running,
            'execution_attempts' => 1,
            'started_at' => now(),
            'error_code' => 'adobe_rate_limited',
            'cause_category' => 'rate_limit',
            'actionability' => 'automatic_retry',
            'user_message_key' => 'connectors.errors.rate_limited',
            'technical_summary' => 'HTTP 429 (adobe_rate_limited)',
        ]);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $job = new ConnectorConnectionCheckJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->failed(new ConnectorConnectionCheckJobExecutionException);

        $row->refresh();
        $account->refresh();

        $this->assertSame(ConnectorConnectionCheckStatus::Failed, $row->status);
        $this->assertSame('adobe_rate_limited', $row->error_code);
        $this->assertSame(ConnectorAccountConnectionStatus::TemporarilyUnavailable, $account->connection_status);
    }

    #[Test]
    public function failed_method_writes_job_failed_when_no_vendor_result(): void
    {
        $account = $this->createConnectorAccount($this->defaultWorkspace(), ['connection_status' => ConnectorAccountConnectionStatus::Untested]);
        $row = $this->createQueuedRow($account, [
            'status' => ConnectorConnectionCheckStatus::Running,
            'execution_attempts' => 1,
            'started_at' => now(),
        ]);
        $retryUntil = $row->retry_until_at->getTimestamp();
        $before = $account->only(['connection_status', 'last_checked_at', 'last_error_cause']);

        $job = new ConnectorConnectionCheckJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->failed(new ConnectorConnectionCheckJobExecutionException);

        $row->refresh();
        $account->refresh();

        $this->assertSame('connection_check_job_failed', $row->error_code);
        $this->assertSame($before, $account->only(['connection_status', 'last_checked_at', 'last_error_cause']));
    }

    #[Test]
    public function executor_exception_is_sanitized_and_duration_recorded(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);
        $retryUntil = $row->retry_until_at->getTimestamp();
        $marker = 'SECRET_MARKER_'.uniqid();

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldReceive('checkConnection')->once()->andThrow(new \RuntimeException($marker));
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        $job = new ConnectorConnectionCheckJob($account->workspace_id, $account->id, $row->id, $retryUntil);

        try {
            $job->handle(app(AdobePaaSConnectionCheckService::class), app(ConnectorConnectionCheckPersistence::class));
            $this->fail('Expected sanitized exception');
        } catch (ConnectorConnectionCheckJobExecutionException $exception) {
            $this->assertSame('Connection-check job execution failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $row->refresh();
        $this->assertGreaterThanOrEqual(0, $row->duration_ms);
        $this->assertStringNotContainsString($marker, json_encode($row->toArray()));
    }

    #[Test]
    public function duplicate_delivery_against_terminal_row_is_no_op(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account, [
            'status' => ConnectorConnectionCheckStatus::Succeeded,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldNotReceive('checkConnection');
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        $job = new ConnectorConnectionCheckJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSConnectionCheckService::class), app(ConnectorConnectionCheckPersistence::class));
    }

    #[Test]
    public function lifecycle_failure_does_not_change_projection(): void
    {
        $account = $this->createConnectorAccount($this->defaultWorkspace(), ['connection_status' => ConnectorAccountConnectionStatus::Untested]);
        $row = $this->createQueuedRow($account);
        $before = $account->fresh()->toArray();

        app(ConnectorConnectionCheckPersistence::class)->writeLifecycleFailure(
            $account->workspace_id,
            $account->id,
            $row->id,
            ConnectorConnectionCheckLifecycleErrorCode::DispatchFailed,
        );

        $account->refresh();
        $this->assertSame($before['connection_status'], $account->connection_status->value);
        $this->assertEquals($before['last_checked_at'], $account->last_checked_at);
    }

    #[Test]
    public function dual_enum_reading_contract_is_unambiguous(): void
    {
        $vendor = 'adobe_rate_limited';
        $lifecycle = 'connection_check_job_failed';

        $this->assertNotNull(ConnectorConnectionCheckErrorCode::tryFrom($vendor));
        $this->assertNull(ConnectorConnectionCheckLifecycleErrorCode::tryFrom($vendor));

        $this->assertNull(ConnectorConnectionCheckErrorCode::tryFrom($lifecycle));
        $this->assertNotNull(ConnectorConnectionCheckLifecycleErrorCode::tryFrom($lifecycle));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createQueuedRow(ConnectorAccount $account, array $overrides = []): ConnectorConnectionCheck
    {
        return ConnectorConnectionCheck::withoutWorkspaceScope()->create(array_merge([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => 'manual',
            'status' => ConnectorConnectionCheckStatus::Queued,
            'execution_attempts' => 0,
            'retry_until_at' => now()->addMinutes(15),
            'next_attempt_at' => null,
            'started_at' => null,
        ], $overrides));
    }
}
