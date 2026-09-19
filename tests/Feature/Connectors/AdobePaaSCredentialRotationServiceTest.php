<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Enums\UserRole;
use App\Models\ConnectorConnectionCheck;
use App\Models\ExternalRecordLink;
use App\Services\Connectors\AdobePaaSCredentialRotationService;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSCredentialMapper;
use App\Support\Connectors\ConnectorConnectionCheckResult;
use App\Support\Connectors\Exceptions\AdobePaaSCredentialRotationConflictException;
use App\Support\Connectors\Exceptions\AdobePaaSCredentialRotationValidationException;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\CreatesMerchantConfirmedExternalRecordLinks;
use Tests\Concerns\InteractsWithEntityTrustFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;

class AdobePaaSCredentialRotationServiceTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use CreatesMerchantConfirmedExternalRecordLinks;
    use InteractsWithEntityTrustFixtures;
    use InteractsWithFieldMappingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();
    }

    #[Test]
    public function successful_replacement_tests_b_outside_transaction_then_atomically_commits_and_audits_it(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount($workspace, [
            'connection_status' => ConnectorAccountConnectionStatus::AttentionRequired,
            'last_error_cause' => ConnectorErrorCause::Authentication,
            'last_error_actionability' => ConnectorErrorActionability::UserActionRequired,
            'last_error_message_key' => 'connectors.errors.invalid_credentials',
            'last_error_at' => now()->subHour(),
        ]);
        $oldCredentials = $account->credentials;
        $replacement = new OAuth1Credentials('ck_b', 'cs_b', 'at_b', 'ts_b');

        $entryTransactionLevel = DB::transactionLevel();
        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldReceive('checkConnection')->once()->withArgs(function ($context) use ($account, $replacement, $entryTransactionLevel): bool {
            $this->assertSame($entryTransactionLevel, DB::transactionLevel(), 'Credential rotation must not open a DB transaction before remote verification.');
            $this->assertSame($account->base_url, $context->baseUrl);
            $this->assertSame($account->store_code, $context->storeCode);
            $this->assertSame($replacement, $context->credentials);

            return true;
        })->andReturn(ConnectorConnectionCheckResult::success());
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        $result = app(AdobePaaSCredentialRotationService::class)->replace($actor, $workspace, $account->id, $replacement);

        $account->refresh();
        $this->assertNotSame($oldCredentials, $account->credentials);
        $this->assertSame('ck_b', $account->credentials['consumer_key']);
        $this->assertSame(ConnectorAccountConnectionStatus::Connected, $account->connection_status);
        $this->assertNotNull($account->last_checked_at);
        $this->assertTrue($account->last_checked_at->equalTo($account->last_successful_check_at));
        $this->assertNull($account->last_error_cause);
        $this->assertNull($account->last_error_actionability);
        $this->assertNull($account->last_error_message_key);
        $this->assertNull($account->last_error_at);
        $this->assertTrue($result->hasCredentials);

        $check = ConnectorConnectionCheck::withoutWorkspaceScope()
            ->where('connector_account_id', $account->id)
            ->sole();
        $this->assertSame(ConnectorConnectionCheckTrigger::CredentialsReplacement, $check->trigger);
        $this->assertSame(ConnectorConnectionCheckStatus::Succeeded, $check->status);
        $this->assertSame($actor->getKey(), $check->initiated_by_user_id);
        $this->assertSame(1, $check->execution_attempts);
        $this->assertSame(200, $check->http_status);
        $this->assertTrue($check->finished_at->equalTo($account->last_successful_check_at));
    }

    #[Test]
    public function failed_b_validation_preserves_a_and_current_connection_projection(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $successfulAt = now()->subMinutes(10)->startOfSecond();
        $account = $this->createConnectorAccount($workspace, [
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'last_checked_at' => $successfulAt,
            'last_successful_check_at' => $successfulAt,
        ]);
        $oldCredentials = $account->credentials;

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldReceive('checkConnection')->once()->andReturn(
            ConnectorConnectionCheckResult::httpFailure(
                ConnectorConnectionCheckErrorCode::AdobeAccessRejectedUndetermined,
                401,
            ),
        );
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        try {
            app(AdobePaaSCredentialRotationService::class)->replace(
                $actor,
                $workspace,
                $account->id,
                new OAuth1Credentials('bad_ck', 'bad_cs', 'bad_at', 'bad_ts'),
            );
            $this->fail('Expected failed replacement credentials to be rejected.');
        } catch (AdobePaaSCredentialRotationValidationException $exception) {
            $this->assertSame(ConnectorConnectionCheckErrorCode::AdobeAccessRejectedUndetermined, $exception->result->errorCode);
        }

        $account->refresh();
        $this->assertSame($oldCredentials, $account->credentials);
        $this->assertSame(ConnectorAccountConnectionStatus::Connected, $account->connection_status);
        $this->assertTrue($account->last_successful_check_at->equalTo($successfulAt));
        $this->assertSame(0, ConnectorConnectionCheck::withoutWorkspaceScope()->where('connector_account_id', $account->id)->count());
    }

    #[Test]
    public function concurrent_configuration_change_after_b_test_aborts_commit_and_preserves_newer_state(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount($workspace);
        $newerCredentials = AdobePaaSCredentialMapper::toStorageArray(new OAuth1Credentials('ck_newer', 'cs_newer', 'at_newer', 'ts_newer'));

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldReceive('checkConnection')->once()->andReturnUsing(function () use ($account, $newerCredentials) {
            $account->forceFill([
                'tenant_context' => 'concurrent-change',
                'credentials' => $newerCredentials,
            ])->save();

            return ConnectorConnectionCheckResult::success();
        });
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        $this->expectException(AdobePaaSCredentialRotationConflictException::class);

        try {
            app(AdobePaaSCredentialRotationService::class)->replace(
                $actor,
                $workspace,
                $account->id,
                new OAuth1Credentials('ck_b', 'cs_b', 'at_b', 'ts_b'),
            );
        } finally {
            $account->refresh();
            $this->assertSame('concurrent-change', $account->tenant_context);
            $this->assertSame('ck_newer', $account->credentials['consumer_key']);
            $this->assertSame(0, ConnectorConnectionCheck::withoutWorkspaceScope()->where('connector_account_id', $account->id)->count());
        }
    }

    #[Test]
    public function authorization_is_checked_before_any_remote_verification(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $actor = $this->createStaffUser(UserRole::Merchandiser);

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldNotReceive('checkConnection');
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        $this->expectException(AuthorizationException::class);
        app(AdobePaaSCredentialRotationService::class)->replace(
            $actor,
            $workspace,
            $account->id,
            new OAuth1Credentials('ck_b', 'cs_b', 'at_b', 'ts_b'),
        );
    }

    #[Test]
    public function successful_rotation_of_disabled_account_preserves_disabled_state(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount($workspace, [
            'is_enabled' => false,
            'connection_status' => ConnectorAccountConnectionStatus::Disabled,
        ]);

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldReceive('checkConnection')->once()->andReturn(ConnectorConnectionCheckResult::success());
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        app(AdobePaaSCredentialRotationService::class)->replace(
            $actor,
            $workspace,
            $account->id,
            new OAuth1Credentials('ck_disabled', 'cs_disabled', 'at_disabled', 'ts_disabled'),
        );

        $account->refresh();
        $this->assertFalse($account->is_enabled);
        $this->assertSame(ConnectorAccountConnectionStatus::Disabled, $account->connection_status);
        $this->assertSame('ck_disabled', $account->credentials['consumer_key']);
        $this->assertNotNull($account->last_successful_check_at);
    }

    #[Test]
    public function successful_rotation_preserves_merchant_confirmed_entity_trust(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount($workspace);
        $this->prepareEntityTrustConfiguration($account);
        [, $variant] = $this->createSimpleEntityTrustProduct($workspace, 'ROTATE-SKU');
        $link = ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $workspace,
                $account->id,
                $variant,
                'ROTATE-SKU',
                '5101',
                $this->createWorkspaceActor($workspace),
            ),
        );

        $capability = Mockery::mock(AdobePaaSConnectionCheckCapability::class);
        $capability->shouldReceive('checkConnection')->once()->andReturn(ConnectorConnectionCheckResult::success());
        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $capability);

        app(AdobePaaSCredentialRotationService::class)->replace(
            $actor,
            $workspace,
            $account->id,
            new OAuth1Credentials('ck_rot', 'cs_rot', 'at_rot', 'ts_rot'),
        );

        $link->refresh();
        $this->assertTrue($link->hasMerchantConfirmedTrust());
        $this->assertSame('5101', $link->external_record_discriminator);
    }
}
