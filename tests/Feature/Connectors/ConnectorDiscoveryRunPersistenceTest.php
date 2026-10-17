<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorDiscoveryRunLifecycleErrorCode;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Jobs\Connectors\ConnectorDiscoveryRunJob;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSource;
use App\Services\Connectors\AdobePaaSDiscoveryService;
use App\Services\Connectors\ConnectorDiscoveryRunPersistence;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapability;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ConnectorDiscoveryRunPersistenceTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    private ConnectorDiscoveryRunPersistence $persistence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);

        $this->persistence = app(ConnectorDiscoveryRunPersistence::class);
    }

    #[Test]
    public function pre_execution_account_disabled_preserves_retryable_vendor_classification(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createRunningRowWithVendorClassification($account);
        $original = $this->vendorClassificationSnapshot($row);

        $account->update(['is_enabled' => false]);

        $slot = $this->persistence->reserveExecutionSlot($account->workspace_id, $account->id, $row->id);

        $this->assertFalse($slot['reserved']);
        $row->refresh();
        $this->assertVendorClassificationPreserved($row, $original);
        $this->assertSame(ConnectorDiscoveryRunStatus::Failed, $row->status);
        $this->assertNull($row->next_attempt_at);
        $this->assertNotNull($row->finished_at);
    }

    #[Test]
    public function pre_execution_source_invalid_preserves_retryable_vendor_classification(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createRunningRowWithVendorClassification($account);
        $original = $this->vendorClassificationSnapshot($row);

        ConnectorSchemaSource::query()
            ->whereKey($row->connector_schema_source_id)
            ->update(['endpoint_path' => '//evil.example.com/V1/products']);

        $slot = $this->persistence->reserveExecutionSlot($account->workspace_id, $account->id, $row->id);

        $this->assertFalse($slot['reserved']);
        $row->refresh();
        $this->assertVendorClassificationPreserved($row, $original);
        $this->assertSame(ConnectorDiscoveryRunStatus::Failed, $row->status);
        $this->assertNull($row->next_attempt_at);
        $this->assertNotNull($row->finished_at);
    }

    #[Test]
    public function terminalize_account_disabled_preserves_retryable_vendor_classification(): void
    {
        $account = $this->createConnectorAccount(overrides: ['is_enabled' => false]);
        $row = $this->createRunningRowWithVendorClassification($account);
        $original = $this->vendorClassificationSnapshot($row);

        $this->persistence->terminalizeAccountDisabledBeforeExecution(
            $account->workspace_id,
            $account->id,
            $row->id,
        );

        $row->refresh();
        $this->assertVendorClassificationPreserved($row, $original);
        $this->assertSame(ConnectorDiscoveryRunStatus::Failed, $row->status);
    }

    #[Test]
    public function preserved_automatic_retry_vendor_projection_updates_enabled_newest_account(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createRunningRowWithVendorClassification($account);

        ConnectorSchemaSource::query()
            ->whereKey($row->connector_schema_source_id)
            ->update(['endpoint_path' => '//evil.example.com/V1/products']);

        $this->persistence->reserveExecutionSlot($account->workspace_id, $account->id, $row->id);

        $account->refresh();
        $this->assertSame(ConnectorAccountConnectionStatus::TemporarilyUnavailable, $account->connection_status);
        $this->assertSame(ConnectorErrorCause::RateLimit, $account->last_error_cause);
        $this->assertSame(ConnectorErrorActionability::AutomaticRetry, $account->last_error_actionability);
        $this->assertSame('connectors.errors.rate_limited', $account->last_error_message_key);
        $this->assertNotNull($account->last_error_at);
    }

    #[Test]
    public function account_disabled_without_vendor_result_writes_lifecycle_classification_without_projection(): void
    {
        $account = $this->createConnectorAccount(overrides: ['is_enabled' => false]);
        $row = $this->createQueuedRow($account);
        $originalConnectionStatus = $account->connection_status;

        $slot = $this->persistence->reserveExecutionSlot($account->workspace_id, $account->id, $row->id);

        $this->assertFalse($slot['reserved']);
        $row->refresh();
        $account->refresh();

        $this->assertSame(ConnectorDiscoveryRunStatus::Failed, $row->status);
        $this->assertSame(
            ConnectorDiscoveryRunLifecycleErrorCode::AccountDisabledBeforeExecution->value,
            $row->error_code,
        );
        $this->assertSame(ConnectorErrorCause::Configuration, $row->cause_category);
        $this->assertSame(ConnectorErrorActionability::WorkspaceAdminRequired, $row->actionability);
        $this->assertSame($originalConnectionStatus, $account->connection_status);
        $this->assertNull($account->last_error_at);
    }

    #[Test]
    public function source_invalid_without_vendor_result_writes_lifecycle_classification_without_projection(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);
        $originalConnectionStatus = $account->connection_status;

        ConnectorSchemaSource::query()
            ->whereKey($row->connector_schema_source_id)
            ->update(['endpoint_path' => '//evil.example.com/V1/products']);

        $slot = $this->persistence->reserveExecutionSlot($account->workspace_id, $account->id, $row->id);

        $this->assertFalse($slot['reserved']);
        $row->refresh();
        $account->refresh();

        $this->assertSame(ConnectorDiscoveryRunStatus::Failed, $row->status);
        $this->assertSame(
            ConnectorDiscoveryRunLifecycleErrorCode::SourceInvalidBeforeExecution->value,
            $row->error_code,
        );
        $this->assertSame(ConnectorErrorCause::Configuration, $row->cause_category);
        $this->assertSame(ConnectorErrorActionability::SupportRequired, $row->actionability);
        $this->assertSame($originalConnectionStatus, $account->connection_status);
        $this->assertNull($account->last_error_at);
    }

    #[Test]
    public function source_invalid_after_slot_reservation_preserves_retryable_vendor_classification(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createRunningRowWithVendorClassification($account, [
            'execution_attempts' => 0,
            'next_attempt_at' => null,
        ]);
        $original = $this->vendorClassificationSnapshot($row);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $slot = $this->persistence->reserveExecutionSlot($account->workspace_id, $account->id, $row->id);
        $this->assertTrue($slot['reserved']);

        ConnectorSchemaSource::query()
            ->whereKey($row->connector_schema_source_id)
            ->update(['endpoint_path' => '//evil.example.com/V1/products']);

        $capability = Mockery::mock(AdobePaaSDiscoveryCapability::class);
        $capability->shouldNotReceive('discover');
        $this->app->instance(AdobePaaSDiscoveryCapability::class, $capability);

        $job = new ConnectorDiscoveryRunJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSDiscoveryService::class), $this->persistence);

        $row->refresh();
        $this->assertVendorClassificationPreserved($row, $original);
        $this->assertSame(ConnectorDiscoveryRunStatus::Failed, $row->status);
        $this->assertNull($row->next_attempt_at);
        $this->assertNotNull($row->finished_at);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createQueuedRow(ConnectorAccount $account, array $overrides = []): ConnectorDiscoveryRun
    {
        $source = app(ConnectorDiscoverySourceResolver::class)->resolve($account);

        return ConnectorDiscoveryRun::withoutWorkspaceScope()->create(array_merge([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => 'manual',
            'status' => ConnectorDiscoveryRunStatus::Queued,
            'execution_attempts' => 0,
            'retry_until_at' => now()->addHour(),
            'next_attempt_at' => null,
            'started_at' => null,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRunningRowWithVendorClassification(
        ConnectorAccount $account,
        array $overrides = [],
    ): ConnectorDiscoveryRun {
        return $this->createQueuedRow($account, array_merge([
            'status' => ConnectorDiscoveryRunStatus::Running,
            'execution_attempts' => 1,
            'started_at' => now(),
            'error_code' => 'adobe_rate_limited',
            'cause_category' => 'rate_limit',
            'actionability' => 'automatic_retry',
            'user_message_key' => 'connectors.errors.rate_limited',
            'technical_summary' => 'HTTP 429 (adobe_rate_limited)',
            'next_attempt_at' => now()->addMinutes(5),
        ], $overrides));
    }

    /**
     * @return array{
     *     error_code: ?string,
     *     cause_category: ?ConnectorErrorCause,
     *     actionability: ?ConnectorErrorActionability,
     *     user_message_key: ?string,
     *     technical_summary: ?string,
     * }
     */
    private function vendorClassificationSnapshot(ConnectorDiscoveryRun $row): array
    {
        return [
            'error_code' => $row->error_code,
            'cause_category' => $row->cause_category,
            'actionability' => $row->actionability,
            'user_message_key' => $row->user_message_key,
            'technical_summary' => $row->technical_summary,
        ];
    }

    /**
     * @param  array{
     *     error_code: ?string,
     *     cause_category: ?ConnectorErrorCause,
     *     actionability: ?ConnectorErrorActionability,
     *     user_message_key: ?string,
     *     technical_summary: ?string,
     * }  $original
     */
    private function assertVendorClassificationPreserved(ConnectorDiscoveryRun $row, array $original): void
    {
        $this->assertSame($original['error_code'], $row->error_code);
        $this->assertSame($original['cause_category'], $row->cause_category);
        $this->assertSame($original['actionability'], $row->actionability);
        $this->assertSame($original['user_message_key'], $row->user_message_key);
        $this->assertSame($original['technical_summary'], $row->technical_summary);
    }
}
