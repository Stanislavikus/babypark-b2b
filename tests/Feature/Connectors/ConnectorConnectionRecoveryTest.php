<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Jobs\Connectors\ConnectorConnectionCheckJob;
use App\Jobs\Connectors\ConnectorConnectionRecoveryJob;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Services\Connectors\AdobePaaSConnectionCheckService;
use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use App\Services\Connectors\ConnectorConnectionCheckPersistence;
use App\Services\Connectors\ConnectorConnectionRecoveryScheduler;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapability;
use App\Support\Connectors\ConnectorConnectionCheckResult;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\EnablesConnectorConnectionCheckCapability;
use Tests\TestCase;

class ConnectorConnectionRecoveryTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use EnablesConnectorConnectionCheckCapability;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->enableConnectionCheckCapability();
    }

    #[Test]
    public function initial_transient_failure_schedules_one_hour_recovery_without_polling_healthy_accounts(): void
    {
        Queue::fake();
        $account = $this->createConnectorAccount();
        $source = $this->transientFailure($account, ConnectorConnectionCheckTrigger::Manual, now());
        $this->projectTransientFailure($account, $source);

        app(ConnectorConnectionRecoveryScheduler::class)->scheduleIfEligible(
            $account->workspace_id,
            $account->id,
            $source->id,
        );

        Queue::assertPushed(ConnectorConnectionRecoveryJob::class, function (ConnectorConnectionRecoveryJob $job): bool {
            $delay = $job->delay;
            $this->assertNotNull($delay);
            $this->assertEqualsWithDelta(now()->addHour()->getTimestamp(), $delay->getTimestamp(), 2);
            $this->assertSame('database_connectors', $job->connection);
            $this->assertSame('connectors', $job->queue);

            return true;
        });

        $account->forceFill([
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'last_error_actionability' => null,
            'last_error_at' => null,
        ])->save();

        app(ConnectorConnectionRecoveryScheduler::class)->scheduleIfEligible(
            $account->workspace_id,
            $account->id,
            $source->id,
        );

        Queue::assertPushed(ConnectorConnectionRecoveryJob::class, 1);
    }

    #[Test]
    public function scheduled_recovery_uses_finite_exponential_backoff_and_stops_after_six_cycles(): void
    {
        Queue::fake();
        $account = $this->createConnectorAccount();
        $base = now()->subMinutes(10);

        $rows = [];
        foreach (range(1, 6) as $index) {
            $rows[] = $this->transientFailure(
                $account,
                ConnectorConnectionCheckTrigger::Scheduled,
                $base->copy()->addSeconds($index),
            );
        }

        $fifth = $rows[4];
        $this->projectTransientFailure($account, $fifth);
        app(ConnectorConnectionRecoveryScheduler::class)->scheduleIfEligible(
            $account->workspace_id,
            $account->id,
            $fifth->id,
        );

        Queue::assertPushed(ConnectorConnectionRecoveryJob::class, function (ConnectorConnectionRecoveryJob $job): bool {
            $this->assertEqualsWithDelta(now()->addHours(32)->getTimestamp(), $job->delay->getTimestamp(), 2);

            return true;
        });

        Queue::fake();
        $sixth = $rows[5];
        $this->projectTransientFailure($account, $sixth);
        app(ConnectorConnectionRecoveryScheduler::class)->scheduleIfEligible(
            $account->workspace_id,
            $account->id,
            $sixth->id,
        );

        Queue::assertNothingPushed();
    }

    #[Test]
    public function evidence_bound_recovery_dispatch_creates_system_scheduled_check_only_while_source_is_current(): void
    {
        Queue::fake();
        $account = $this->createConnectorAccount();
        $source = $this->transientFailure($account, ConnectorConnectionCheckTrigger::Manual, now());
        $this->projectTransientFailure($account, $source);

        $checkId = app(ConnectorConnectionCheckDispatchService::class)->executeRecovery(
            $account->workspace_id,
            $account->id,
            $source->id,
        );

        $this->assertNotNull($checkId);
        $row = ConnectorConnectionCheck::withoutWorkspaceScope()->findOrFail($checkId);
        $this->assertSame(ConnectorConnectionCheckTrigger::Scheduled, $row->trigger);
        $this->assertNull($row->initiated_by_user_id);
        $this->assertSame(ConnectorConnectionCheckStatus::Queued, $row->status);
        Queue::assertPushed(ConnectorConnectionCheckJob::class, 1);

        $account->forceFill([
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'last_successful_check_at' => now(),
            'last_error_actionability' => null,
            'last_error_at' => null,
        ])->save();

        $this->assertNull(app(ConnectorConnectionCheckDispatchService::class)->executeRecovery(
            $account->workspace_id,
            $account->id,
            $source->id,
        ));
        $this->assertSame(1, ConnectorConnectionCheck::withoutWorkspaceScope()
            ->where('connector_account_id', $account->id)
            ->where('trigger', ConnectorConnectionCheckTrigger::Scheduled)
            ->count());
    }

    #[Test]
    public function terminal_transient_check_automatically_schedules_recovery_wakeup(): void
    {
        Queue::fake();
        $account = $this->createConnectorAccount();
        $row = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => ConnectorConnectionCheckTrigger::Manual,
            'status' => ConnectorConnectionCheckStatus::Running,
            'execution_attempts' => 2,
            'retry_until_at' => now()->addMinutes(10),
            'started_at' => now()->subMinute(),
        ]);

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldReceive('checkConnection')->once()->andReturn(
            ConnectorConnectionCheckResult::httpFailure(
                ConnectorConnectionCheckErrorCode::AdobeVendorUnavailable,
                503,
            ),
        );
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        $job = new ConnectorConnectionCheckJob(
            $account->workspace_id,
            $account->id,
            $row->id,
            $row->retry_until_at->getTimestamp(),
        );
        $job->handle(
            app(AdobePaaSConnectionCheckService::class),
            app(ConnectorConnectionCheckPersistence::class),
            app(ConnectorConnectionRecoveryScheduler::class),
        );

        $account->refresh();
        $row->refresh();
        $this->assertSame(ConnectorConnectionCheckStatus::Failed, $row->status);
        $this->assertSame(ConnectorAccountConnectionStatus::TemporarilyUnavailable, $account->connection_status);
        Queue::assertPushed(ConnectorConnectionRecoveryJob::class, 1);
    }

    #[Test]
    public function successful_scheduled_recovery_restores_connected_without_chaining_another_wakeup(): void
    {
        Queue::fake();
        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::TemporarilyUnavailable,
            'last_error_actionability' => ConnectorErrorActionability::AutomaticRetry,
            'last_error_at' => now()->subHour(),
        ]);
        $row = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => ConnectorConnectionCheckTrigger::Scheduled,
            'status' => ConnectorConnectionCheckStatus::Queued,
            'execution_attempts' => 0,
            'retry_until_at' => now()->addMinutes(15),
        ]);

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldReceive('checkConnection')->once()->andReturn(ConnectorConnectionCheckResult::success());
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        $job = new ConnectorConnectionCheckJob(
            $account->workspace_id,
            $account->id,
            $row->id,
            $row->retry_until_at->getTimestamp(),
        );
        $job->handle(
            app(AdobePaaSConnectionCheckService::class),
            app(ConnectorConnectionCheckPersistence::class),
            app(ConnectorConnectionRecoveryScheduler::class),
        );

        $account->refresh();
        $this->assertSame(ConnectorAccountConnectionStatus::Connected, $account->connection_status);
        $this->assertNull($account->last_error_actionability);
        Queue::assertNotPushed(ConnectorConnectionRecoveryJob::class);
    }

    #[Test]
    public function recovery_job_is_unique_per_source_failure(): void
    {
        $job = new ConnectorConnectionRecoveryJob('ws', 'account', 'source-check');

        $this->assertSame('source-check', $job->uniqueId());
        $this->assertSame(1209600, $job->uniqueFor);
        $this->assertSame('database_connectors', $job->connection);
        $this->assertSame('connectors', $job->queue);
    }

    private function transientFailure(
        ConnectorAccount $account,
        ConnectorConnectionCheckTrigger $trigger,
        \DateTimeInterface $finishedAt,
    ): ConnectorConnectionCheck {
        $row = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => $trigger,
            'initiated_by_user_id' => null,
            'status' => ConnectorConnectionCheckStatus::Failed,
            'execution_attempts' => 3,
            'retry_until_at' => $finishedAt,
            'cause_category' => ConnectorErrorCause::VendorUnavailable,
            'actionability' => ConnectorErrorActionability::AutomaticRetry,
            'error_code' => 'adobe_vendor_unavailable',
            'http_status' => 503,
            'user_message_key' => 'connectors.errors.vendor_unavailable',
            'finished_at' => $finishedAt,
        ]);

        $row->forceFill(['created_at' => $finishedAt])->save();

        return $row->fresh();
    }

    private function projectTransientFailure(ConnectorAccount $account, ConnectorConnectionCheck $source): void
    {
        $account->forceFill([
            'connection_status' => ConnectorAccountConnectionStatus::TemporarilyUnavailable,
            'last_checked_at' => $source->finished_at,
            'last_error_cause' => ConnectorErrorCause::VendorUnavailable,
            'last_error_actionability' => ConnectorErrorActionability::AutomaticRetry,
            'last_error_message_key' => 'connectors.errors.vendor_unavailable',
            'last_error_at' => $source->finished_at,
        ])->save();
    }
}
