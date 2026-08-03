<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Enums\ConnectorDiscoveryRunLifecycleErrorCode;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Jobs\Connectors\ConnectorConnectionCheckJob;
use App\Jobs\Connectors\ConnectorDiscoveryRunJob;
use App\Jobs\Connectors\ConnectorDiscoveryRunJobExecutionException;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSource;
use App\Services\Connectors\AdobePaaSDiscoveryService;
use App\Services\Connectors\ConnectorDiscoveryRunPersistence;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Support\Connectors\AdobePaaS\AdobePaaSAttributeNormalizer;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapabilityImpl;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryRequestFactory;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryResponseMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryTransportMapper;
use App\Support\Connectors\CanonicalSchemaFieldHash;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\CanonicalSchemaSnapshotHasher;
use App\Support\Connectors\ConnectorDiscoveryAttemptResult;
use App\Support\Connectors\ConnectorDiscoveryNormalizedField;
use App\Support\Connectors\ConnectorDiscoverySnapshotCandidate;
use App\Support\Connectors\ConnectorSchemaSourceEndpointPathValidator;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceInvalidAfterReservationException;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use Carbon\CarbonImmutable;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ConnectorDiscoveryRunJobTest extends TestCase
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
        $retryUntil = now()->addHour()->getTimestamp();
        $job = new ConnectorDiscoveryRunJob('ws', 'acct', 'run', $retryUntil);

        $this->assertSame($retryUntil, $job->retryUntil()->getTimestamp());
        $this->assertSame(1, $job->maxExceptions);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame(900, $job->timeout);
    }

    #[Test]
    public function without_overlapping_middleware_is_configured(): void
    {
        $job = new ConnectorDiscoveryRunJob('ws', 'acct', 'run', now()->addHour()->getTimestamp());
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame(1100, $middleware[0]->expiresAfter);
        $this->assertSame(30, $middleware[0]->releaseAfter);
        $this->assertTrue($middleware[0]->shareKey);
        $this->assertSame('connector-account:acct', $middleware[0]->key);
    }

    #[Test]
    public function discovery_and_connection_check_jobs_share_account_lock_key(): void
    {
        $accountId = '00000000-0000-4000-8000-000000000101';
        $otherAccountId = '00000000-0000-4000-8000-000000000202';

        $discoveryMiddleware = (new ConnectorDiscoveryRunJob('ws', $accountId, 'run', now()->addHour()->getTimestamp()))
            ->middleware()[0];
        $connectionCheckMiddleware = (new ConnectorConnectionCheckJob('ws', $accountId, 'check', now()->addHour()->getTimestamp()))
            ->middleware()[0];
        $otherAccountMiddleware = (new ConnectorDiscoveryRunJob('ws', $otherAccountId, 'run', now()->addHour()->getTimestamp()))
            ->middleware()[0];

        $this->assertSame('connector-account:'.$accountId, $discoveryMiddleware->key);
        $this->assertSame($discoveryMiddleware->key, $connectionCheckMiddleware->key);
        $this->assertNotSame($discoveryMiddleware->key, $otherAccountMiddleware->key);
    }

    #[Test]
    public function success_terminalizes_run(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);
        $retryUntil = $row->retry_until_at->getTimestamp();
        $candidate = $this->sampleSuccessResult()->snapshotCandidate;

        $capability = Mockery::mock(AdobePaaSDiscoveryCapability::class);
        $capability->shouldReceive('discover')->once()->andReturn($this->sampleSuccessResult());

        $this->app->instance(AdobePaaSDiscoveryCapability::class, $capability);

        $job = new ConnectorDiscoveryRunJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSDiscoveryService::class), app(ConnectorDiscoveryRunPersistence::class));

        $row->refresh();
        $account->refresh();

        $this->assertSame(ConnectorDiscoveryRunStatus::Succeeded, $row->status);
        $this->assertNotNull($row->finished_at);
        $this->assertNotNull($row->snapshot_id);
        $this->assertSame($candidate->fieldsReceived(), $row->fields_received);
        $this->assertNotNull($account->last_successful_discovery_at);
    }

    #[Test]
    public function non_retryable_failure_writes_terminal_classification(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $result = ConnectorDiscoveryAttemptResult::httpFailure(
            ConnectorDiscoveryRunErrorCode::AdobeInvalidCredentials,
            401,
        );

        $capability = Mockery::mock(AdobePaaSDiscoveryCapability::class);
        $capability->shouldReceive('discover')->once()->andReturn($result);
        $this->app->instance(AdobePaaSDiscoveryCapability::class, $capability);

        $job = new ConnectorDiscoveryRunJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSDiscoveryService::class), app(ConnectorDiscoveryRunPersistence::class));

        $row->refresh();
        $account->refresh();

        $this->assertSame(ConnectorDiscoveryRunStatus::Failed, $row->status);
        $this->assertSame('adobe_invalid_credentials', $row->error_code);
        $this->assertSame(ConnectorErrorCause::Authentication, $row->cause_category);
        $this->assertSame(ConnectorErrorActionability::UserActionRequired, $row->actionability);
        $this->assertSame('connectors.errors.invalid_credentials', $row->user_message_key);
        $this->assertNotNull($account->last_error_at);
    }

    #[Test]
    public function intermediate_retry_persists_classification_without_terminalizing(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $result = ConnectorDiscoveryAttemptResult::httpFailure(
            ConnectorDiscoveryRunErrorCode::AdobeVendorUnavailable,
            503,
        );

        $capability = Mockery::mock(AdobePaaSDiscoveryCapability::class);
        $capability->shouldReceive('discover')->once()->andReturn($result);
        $this->app->instance(AdobePaaSDiscoveryCapability::class, $capability);

        $job = new ConnectorDiscoveryRunJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSDiscoveryService::class), app(ConnectorDiscoveryRunPersistence::class));

        $row->refresh();
        $this->assertSame(ConnectorDiscoveryRunStatus::Running, $row->status);
        $this->assertSame('adobe_vendor_unavailable', $row->error_code);
        $this->assertNotNull($row->next_attempt_at);
        $this->assertNull($row->finished_at);
    }

    #[Test]
    public function account_disabled_before_execution_writes_lifecycle_outcome(): void
    {
        $account = $this->createConnectorAccount($this->defaultWorkspace(), ['is_enabled' => false]);
        $row = $this->createQueuedRow($account);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $capability = Mockery::mock(AdobePaaSDiscoveryCapability::class);
        $capability->shouldNotReceive('discover');
        $this->app->instance(AdobePaaSDiscoveryCapability::class, $capability);

        $job = new ConnectorDiscoveryRunJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSDiscoveryService::class), app(ConnectorDiscoveryRunPersistence::class));

        $row->refresh();

        $this->assertSame(ConnectorDiscoveryRunStatus::Failed, $row->status);
        $this->assertSame('discovery_account_disabled_before_execution', $row->error_code);
        $this->assertSame(ConnectorErrorCause::Configuration, $row->cause_category);
        $this->assertSame(ConnectorErrorActionability::WorkspaceAdminRequired, $row->actionability);
    }

    #[Test]
    public function next_attempt_at_blocks_early_redelivery(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account, [
            'status' => ConnectorDiscoveryRunStatus::Running,
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

        $capability = Mockery::mock(AdobePaaSDiscoveryCapability::class);
        $capability->shouldNotReceive('discover');
        $this->app->instance(AdobePaaSDiscoveryCapability::class, $capability);

        $job = new ConnectorDiscoveryRunJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSDiscoveryService::class), app(ConnectorDiscoveryRunPersistence::class));

        $row->refresh();
        $this->assertSame(1, $row->execution_attempts);
    }

    #[Test]
    public function failed_method_preserves_vendor_classification(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account, [
            'status' => ConnectorDiscoveryRunStatus::Running,
            'execution_attempts' => 1,
            'started_at' => now(),
            'error_code' => 'adobe_rate_limited',
            'cause_category' => 'rate_limit',
            'actionability' => 'automatic_retry',
            'user_message_key' => 'connectors.errors.rate_limited',
            'technical_summary' => 'HTTP 429 (adobe_rate_limited)',
        ]);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $job = new ConnectorDiscoveryRunJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->failed(new ConnectorDiscoveryRunJobExecutionException);

        $row->refresh();
        $this->assertSame(ConnectorDiscoveryRunStatus::Failed, $row->status);
        $this->assertSame('adobe_rate_limited', $row->error_code);
    }

    #[Test]
    public function failed_method_writes_job_failed_when_no_vendor_result(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account, [
            'status' => ConnectorDiscoveryRunStatus::Running,
            'execution_attempts' => 1,
            'started_at' => now(),
        ]);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $job = new ConnectorDiscoveryRunJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->failed(new ConnectorDiscoveryRunJobExecutionException);

        $row->refresh();
        $this->assertSame('discovery_job_failed', $row->error_code);
    }

    #[Test]
    public function executor_exception_is_sanitized_and_duration_recorded(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);
        $retryUntil = $row->retry_until_at->getTimestamp();
        $marker = 'SECRET_MARKER_'.uniqid();

        $capability = Mockery::mock(AdobePaaSDiscoveryCapability::class);
        $capability->shouldReceive('discover')->once()->andThrow(new \RuntimeException($marker));
        $this->app->instance(AdobePaaSDiscoveryCapability::class, $capability);

        $job = new ConnectorDiscoveryRunJob($account->workspace_id, $account->id, $row->id, $retryUntil);

        try {
            $job->handle(app(AdobePaaSDiscoveryService::class), app(ConnectorDiscoveryRunPersistence::class));
            $this->fail('Expected sanitized exception');
        } catch (ConnectorDiscoveryRunJobExecutionException $exception) {
            $this->assertSame('Connector discovery job execution failed.', $exception->getMessage());
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
            'status' => ConnectorDiscoveryRunStatus::Succeeded,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $capability = Mockery::mock(AdobePaaSDiscoveryCapability::class);
        $capability->shouldNotReceive('discover');
        $this->app->instance(AdobePaaSDiscoveryCapability::class, $capability);

        $job = new ConnectorDiscoveryRunJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSDiscoveryService::class), app(ConnectorDiscoveryRunPersistence::class));
    }

    #[Test]
    public function dual_enum_reading_contract_is_unambiguous(): void
    {
        $vendor = 'adobe_rate_limited';
        $lifecycle = 'discovery_job_failed';

        $this->assertNotNull(ConnectorDiscoveryRunErrorCode::tryFrom($vendor));
        $this->assertNull(ConnectorDiscoveryRunLifecycleErrorCode::tryFrom($vendor));

        $this->assertNull(ConnectorDiscoveryRunErrorCode::tryFrom($lifecycle));
        $this->assertNotNull(ConnectorDiscoveryRunLifecycleErrorCode::tryFrom($lifecycle));
    }

    #[Test]
    public function duplicate_field_keys_classify_as_schema_validation_without_job_failed(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);
        $retryUntil = $row->retry_until_at->getTimestamp();

        $duplicateAttribute = json_decode(
            '{"attribute_code":"color","frontend_input":"text","scope":"global"}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $transport = new class($duplicateAttribute) implements ConnectorHttpTransport
        {
            public function __construct(private readonly \stdClass $duplicateAttribute) {}

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [$this->duplicateAttribute, $this->duplicateAttribute],
                    'total_count' => 2,
                ], JSON_THROW_ON_ERROR));
            }
        };

        $this->app->instance(
            AdobePaaSDiscoveryCapability::class,
            new AdobePaaSDiscoveryCapabilityImpl(
                new AdobePaaSDiscoveryRequestFactory(
                    new OAuth1RequestSigner,
                    new ConnectorSchemaSourceEndpointPathValidator,
                ),
                $transport,
                new AdobePaaSDiscoveryResponseMapper,
                new AdobePaaSDiscoveryTransportMapper,
                new AdobePaaSAttributeNormalizer,
                new CanonicalSchemaFieldHasher,
                new CanonicalSchemaSnapshotHasher,
            ),
        );

        $job = new ConnectorDiscoveryRunJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSDiscoveryService::class), app(ConnectorDiscoveryRunPersistence::class));

        $row->refresh();
        $this->assertSame(ConnectorDiscoveryRunStatus::Failed, $row->status);
        $this->assertSame('discovery_schema_validation_failed', $row->error_code);
        $this->assertSame(ConnectorErrorActionability::SupportRequired, $row->actionability);
        $this->assertNotSame('discovery_job_failed', $row->error_code);
        $this->assertNull($row->snapshot_id);
    }

    #[Test]
    public function service_reverify_failure_after_slot_reservation_uses_dedicated_boundary(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);
        $persistence = app(ConnectorDiscoveryRunPersistence::class);

        $slot = $persistence->reserveExecutionSlot($account->workspace_id, $account->id, $row->id);
        $this->assertTrue($slot['reserved']);

        ConnectorSchemaSource::query()
            ->whereKey($row->connector_schema_source_id)
            ->update(['endpoint_path' => '//evil.example.com/V1/products']);

        $capability = Mockery::mock(AdobePaaSDiscoveryCapability::class);
        $capability->shouldNotReceive('discover');
        $this->app->instance(AdobePaaSDiscoveryCapability::class, $capability);

        try {
            app(AdobePaaSDiscoveryService::class)->execute($account->workspace_id, $account->id, $row->id);
            $this->fail('Expected ConnectorDiscoverySourceInvalidAfterReservationException');
        } catch (ConnectorDiscoverySourceInvalidAfterReservationException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function service_source_invalid_wins_over_incomplete_credentials_regression(): void
    {
        $account = $this->createConnectorAccount(overrides: [
            'base_url' => null,
            'store_code' => null,
            'credentials' => [],
        ]);
        $row = $this->createQueuedRow($account);
        $persistence = app(ConnectorDiscoveryRunPersistence::class);

        $slot = $persistence->reserveExecutionSlot($account->workspace_id, $account->id, $row->id);
        $this->assertTrue($slot['reserved']);

        ConnectorSchemaSource::query()
            ->whereKey($row->connector_schema_source_id)
            ->update(['endpoint_path' => '//evil.example.com/V1/products']);

        try {
            app(AdobePaaSDiscoveryService::class)->execute($account->workspace_id, $account->id, $row->id);
            $this->fail('Expected ConnectorDiscoverySourceInvalidAfterReservationException');
        } catch (ConnectorDiscoverySourceInvalidAfterReservationException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function job_terminalizes_when_source_invalid_before_first_http_call(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);
        $retryUntil = $row->retry_until_at->getTimestamp();
        $originalLastDiscoveryAt = $account->last_discovery_at;

        ConnectorSchemaSource::query()
            ->whereKey($row->connector_schema_source_id)
            ->update(['endpoint_path' => '//evil.example.com/V1/products']);

        $capability = Mockery::mock(AdobePaaSDiscoveryCapability::class);
        $capability->shouldNotReceive('discover');
        $this->app->instance(AdobePaaSDiscoveryCapability::class, $capability);

        $job = new ConnectorDiscoveryRunJob($account->workspace_id, $account->id, $row->id, $retryUntil);
        $job->handle(app(AdobePaaSDiscoveryService::class), app(ConnectorDiscoveryRunPersistence::class));

        $row->refresh();
        $account->refresh();

        $this->assertSame(ConnectorDiscoveryRunStatus::Failed, $row->status);
        $this->assertSame('discovery_source_invalid_before_execution', $row->error_code);
        $this->assertNotSame('discovery_job_failed', $row->error_code);
        $this->assertSame($originalLastDiscoveryAt?->toJSON(), $account->last_discovery_at?->toJSON());
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

    private function sampleSuccessResult(): ConnectorDiscoveryAttemptResult
    {
        $normalizer = new AdobePaaSAttributeNormalizer;
        $fieldHasher = new CanonicalSchemaFieldHasher;
        $snapshotHasher = new CanonicalSchemaSnapshotHasher;

        $raw = json_decode(
            '{"attribute_code":"color","frontend_input":"text","scope":"global"}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );
        $canonicalField = $normalizer->normalize($raw);
        $normalizedField = new ConnectorDiscoveryNormalizedField(
            $canonicalField,
            $fieldHasher->hash($canonicalField),
        );
        $candidate = ConnectorDiscoverySnapshotCandidate::create(
            [$normalizedField],
            $snapshotHasher->hash([
                CanonicalSchemaFieldHash::create(
                    $canonicalField->externalFieldKey(),
                    $fieldHasher->hash($canonicalField),
                ),
            ]),
            CarbonImmutable::now(),
        );

        return ConnectorDiscoveryAttemptResult::success($candidate);
    }
}
