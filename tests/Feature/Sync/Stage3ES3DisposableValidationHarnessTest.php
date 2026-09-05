<?php

namespace Tests\Feature\Sync;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\CreatesMerchantConfirmedExternalRecordLinks;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class Stage3ES3DisposableValidationHarnessTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use CreatesMerchantConfirmedExternalRecordLinks;
    use RefreshDatabase;

    private static ?string $previousAppEnv = null;

    public function createApplication(): Application
    {
        self::$previousAppEnv ??= getenv('APP_ENV') !== false ? (string) getenv('APP_ENV') : null;

        putenv('APP_ENV=stage3e-validation');
        $_ENV['APP_ENV'] = 'stage3e-validation';
        $_SERVER['APP_ENV'] = 'stage3e-validation';

        return parent::createApplication();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$previousAppEnv === null) {
            putenv('APP_ENV');
            unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
        } else {
            putenv('APP_ENV='.self::$previousAppEnv);
            $_ENV['APP_ENV'] = self::$previousAppEnv;
            $_SERVER['APP_ENV'] = self::$previousAppEnv;
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        config()->set('adobe_stage3e_validation.allow_host', 'shop.example.com');
        Storage::disk('local')->deleteDirectory((string) config('adobe_stage3e_validation.artifact_directory', 'stage3e-validation'));
    }

    #[Test]
    public function command_is_present_in_stage3e_validation_environment(): void
    {
        $commands = app(Kernel::class)->all();

        $this->assertArrayHasKey('adobe:stage3e-validate', $commands);
        $this->assertTrue(app()->environment('stage3e-validation'));
    }

    #[Test]
    public function host_mismatch_aborts_before_http(): void
    {
        $transport = $this->bindTransport();
        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-HOST-1');
        $this->createTrustedVariantLink($account, $variant, 101);

        $exitCode = Artisan::call('adobe:stage3e-validate', [
            'connector-account-id' => $account->id,
            'product-variant-id' => (string) $variant->id,
            '--expect-host' => 'other.example.com',
            '--execute-real-writes' => true,
            '--ack-write-sku' => 'B2BVAL-HOST-1',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, $transport->sendCount);
        $this->assertStringContainsString('expect_host_mismatch', Artisan::output());
    }

    #[Test]
    public function non_https_account_is_rejected_before_http(): void
    {
        $transport = $this->bindTransport();
        $account = $this->createConnectorAccount(null, ['base_url' => 'http://shop.example.com']);
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-HTTP-1');
        $this->createTrustedVariantLink($account, $variant, 102);

        $exitCode = $this->runCommand($account, $variant, 'B2BVAL-HTTP-1');

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, $transport->sendCount);
        $this->assertStringContainsString('connector_account_base_url_not_https', Artisan::output());
    }

    #[Test]
    public function all_store_code_is_rejected_before_http(): void
    {
        $transport = $this->bindTransport();
        $account = $this->createConnectorAccount(null, ['store_code' => 'all']);
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-ALL-1');
        $this->createTrustedVariantLink($account, $variant, 103);

        $exitCode = $this->runCommand($account, $variant, 'B2BVAL-ALL-1');

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, $transport->sendCount);
        $this->assertStringContainsString('store_code_all_forbidden', Artisan::output());
    }

    #[Test]
    public function disabled_and_wrong_profile_accounts_are_rejected_before_http(): void
    {
        $transport = $this->bindTransport();
        $disabledAccount = $this->createConnectorAccount(null, [
            'is_enabled' => false,
        ]);
        [$_, $disabledVariant] = $this->createValidationVariant($disabledAccount->workspace, 'B2BVAL-ACC-1');
        $this->createTrustedVariantLink($disabledAccount, $disabledVariant, 104);

        $disabledExitCode = $this->runCommand($disabledAccount, $disabledVariant, 'B2BVAL-ACC-1');

        $this->assertSame(1, $disabledExitCode);
        $this->assertSame(0, $transport->sendCount);
        $this->assertStringContainsString('connector_account_disabled', Artisan::output());

        $wrongProfileAccount = $this->createConnectorAccount(null, [
            'auth_profile' => 'test_sync_support',
            'connection_status' => ConnectorAccountConnectionStatus::Untested,
        ]);
        [$_, $wrongProfileVariant] = $this->createValidationVariant($wrongProfileAccount->workspace, 'B2BVAL-ACC-2');
        $this->createTrustedVariantLink($wrongProfileAccount, $wrongProfileVariant, 1004);

        $wrongProfileExitCode = $this->runCommand($wrongProfileAccount, $wrongProfileVariant, 'B2BVAL-ACC-2');

        $this->assertSame(1, $wrongProfileExitCode);
        $this->assertSame(0, $transport->sendCount);
        $this->assertStringContainsString('connector_account_auth_profile_unsupported', Artisan::output());
    }

    #[Test]
    public function non_b2bval_target_and_mixed_workspace_are_rejected(): void
    {
        $transport = $this->bindTransport();
        $account = $this->createConnectorAccount();
        [$_, $plainVariant] = $this->createValidationVariant($account->workspace, 'SKU-PLAIN');
        $this->createTrustedVariantLink($account, $plainVariant, 105, externalIdentifier: 'SKU-PLAIN');

        $plainExitCode = $this->runCommand($account, $plainVariant, 'SKU-PLAIN');

        $this->assertSame(1, $plainExitCode);
        $this->assertSame(0, $transport->sendCount);
        $this->assertStringContainsString('product_variant_sku_not_validation_prefixed', Artisan::output());

        [$product, $validationVariant] = $this->createValidationVariant($account->workspace, 'B2BVAL-MIXED-1');
        $this->createTrustedVariantLink($account, $validationVariant, 1005);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-MIXED-PLAIN',
            'is_active' => true,
        ]);

        $mixedExitCode = $this->runCommand($account, $validationVariant, 'B2BVAL-MIXED-1');

        $this->assertSame(1, $mixedExitCode);
        $this->assertSame(0, $transport->sendCount);
        $this->assertStringContainsString('workspace_contains_non_validation_variants', Artisan::output());
    }

    #[Test]
    public function ordinary_product_without_valid_validation_variant_is_rejected(): void
    {
        $transport = $this->bindTransport();
        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-ORDINARY-1');
        $this->createTrustedVariantLink($account, $variant, 106);

        Product::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'ORDINARY-PRODUCT',
            'name' => 'Ordinary Product',
            'is_active' => true,
        ]);

        $exitCode = $this->runCommand($account, $variant, 'B2BVAL-ORDINARY-1');

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, $transport->sendCount);
        $this->assertStringContainsString('workspace_contains_ordinary_products', Artisan::output());
    }

    #[Test]
    public function foreign_workspace_variant_is_rejected_before_http(): void
    {
        $transport = $this->bindTransport();
        $account = $this->createConnectorAccount();
        $otherWorkspace = Workspace::query()->create(['name' => 'Foreign WS', 'is_default' => false]);
        [$_, $variant] = $this->createValidationVariant($otherWorkspace, 'B2BVAL-FOREIGN-1');

        $exitCode = $this->runCommand($account, $variant, 'B2BVAL-FOREIGN-1');

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, $transport->sendCount);
        $this->assertStringContainsString('product_variant_workspace_mismatch', Artisan::output());
    }

    #[Test]
    public function missing_legacy_untrusted_and_incomplete_erls_are_rejected_before_http(): void
    {
        $transport = $this->bindTransport();
        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-ERL-1');

        $missing = $this->runCommand($account, $variant, 'B2BVAL-ERL-1');
        $this->assertSame(1, $missing);
        $this->assertStringContainsString('trusted_external_record_link_missing', Artisan::output());

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'B2BVAL-ERL-1',
        ]);

        $legacy = $this->runCommand($account, $variant, 'B2BVAL-ERL-1');
        $this->assertSame(1, $legacy);
        $this->assertStringContainsString('trusted_external_record_link_untrusted_or_incomplete', Artisan::output());
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function discriminator_and_external_identifier_must_match_variant_identity(): void
    {
        $transport = $this->bindTransport();
        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-ID-1');
        $actor = $this->createWorkspaceActor($account->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'OTHER-SKU',
            'trust_origin' => 'merchant_confirmed',
            'external_record_discriminator' => 'not-int',
            'established_by_workspace_user_id' => $actor->id,
            'established_at' => now(),
        ]);

        $exitCode = $this->runCommand($account, $variant, 'B2BVAL-ID-1');

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, $transport->sendCount);
        $output = Artisan::output();
        $this->assertStringContainsString('external_record_link_identifier_sku_mismatch', $output);
        $this->assertStringContainsString('external_record_discriminator_invalid', $output);
    }

    #[Test]
    public function remote_entity_bound_pre_read_mismatches_are_rejected_before_put(): void
    {
        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/safe-sync/handshake')) {
                return $this->handshakeResult();
            }

            return new ConnectorHttpResult(200, [], json_encode([
                'logical_entity_id' => 999,
                'sku' => 'B2BVAL-READ-1',
                'type_id' => 'simple',
                'name' => 'Mismatch',
            ], JSON_THROW_ON_ERROR));
        });

        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-READ-1');
        $this->createTrustedVariantLink($account, $variant, 107);

        $exitCode = $this->runCommand($account, $variant, 'B2BVAL-READ-1');

        $this->assertSame(1, $exitCode);
        $puts = array_values(array_filter(
            $transport->recordedRequests,
            static fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'PUT',
        ));
        $this->assertCount(0, $puts);
        $this->assertStringContainsString('safe_sync_pre_read_failed', Artisan::output());
    }

    #[Test]
    public function remote_entity_bound_pre_read_sku_mismatch_is_rejected_before_put(): void
    {
        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/safe-sync/handshake')) {
                return $this->handshakeResult();
            }

            return new ConnectorHttpResult(200, [], json_encode([
                'logical_entity_id' => 108,
                'sku' => 'OTHER-SKU',
                'type_id' => 'simple',
                'name' => 'Mismatch',
            ], JSON_THROW_ON_ERROR));
        });

        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-READ-2');
        $this->createTrustedVariantLink($account, $variant, 108);

        $exitCode = $this->runCommand($account, $variant, 'B2BVAL-READ-2');

        $this->assertSame(1, $exitCode);
        $puts = array_values(array_filter(
            $transport->recordedRequests,
            static fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'PUT',
        ));
        $this->assertCount(0, $puts);
        $this->assertStringContainsString('safe_sync_pre_read_failed', Artisan::output());
    }

    #[Test]
    public function no_real_put_happens_without_execute_real_writes_or_exact_acknowledgement(): void
    {
        $transport = $this->bindTransport();
        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-ACK-1');
        $this->createTrustedVariantLink($account, $variant, 109);

        $withoutExecute = Artisan::call('adobe:stage3e-validate', [
            'connector-account-id' => $account->id,
            'product-variant-id' => (string) $variant->id,
            '--expect-host' => 'shop.example.com',
            '--ack-write-sku' => 'B2BVAL-ACK-1',
        ]);
        $this->assertSame(1, $withoutExecute);
        $this->assertSame(0, $transport->sendCount);
        $this->assertStringContainsString('execute_real_writes_acknowledgement_missing', Artisan::output());

        $wrongAck = Artisan::call('adobe:stage3e-validate', [
            'connector-account-id' => $account->id,
            'product-variant-id' => (string) $variant->id,
            '--expect-host' => 'shop.example.com',
            '--execute-real-writes' => true,
            '--ack-write-sku' => 'B2BVAL-WRONG',
        ]);
        $this->assertSame(1, $wrongAck);
        $this->assertSame(0, $transport->sendCount);
        $this->assertStringContainsString('ack_write_sku_mismatch', Artisan::output());
    }

    #[Test]
    public function module_version_below_s2_minimum_fails_before_put(): void
    {
        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/safe-sync/handshake')) {
                return $this->handshakeResult(moduleVersion: '0.2.0');
            }

            return $this->productReadResult(116, 'B2BVAL-MOD-1', 'Original Name');
        });
        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-MOD-1');
        $this->createTrustedVariantLink($account, $variant, 116);

        $exitCode = $this->runCommand($account, $variant, 'B2BVAL-MOD-1');

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, $transport->sendCount);
        $puts = array_values(array_filter(
            $transport->recordedRequests,
            static fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'PUT',
        ));
        $this->assertCount(0, $puts);
        $this->assertStringContainsString('safe_sync_module_version_below_s2_minimum', Artisan::output());
    }

    #[Test]
    public function missing_simple_write_family_fails_before_put(): void
    {
        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/safe-sync/handshake')) {
                return $this->handshakeResult(
                    supportedOperationFamilies: ['entity_bound_product_read'],
                );
            }

            return $this->productReadResult(117, 'B2BVAL-FAM-1', 'Original Name');
        });
        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-FAM-1');
        $this->createTrustedVariantLink($account, $variant, 117);

        $exitCode = $this->runCommand($account, $variant, 'B2BVAL-FAM-1');

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, $transport->sendCount);
        $puts = array_values(array_filter(
            $transport->recordedRequests,
            static fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'PUT',
        ));
        $this->assertCount(0, $puts);
        $this->assertStringContainsString('safe_sync_simple_write_family_not_advertised', Artisan::output());
    }

    #[Test]
    public function decorator_is_local_and_does_not_replace_global_transport_binding(): void
    {
        $transport = $this->bindTransport(fn (ConnectorOutboundRequest $request): ConnectorHttpResult => $this->baselineResponder($request));
        $before = app(ConnectorHttpTransport::class);
        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-LOCAL-1');
        $this->createTrustedVariantLink($account, $variant, 110);

        $exitCode = $this->runCommand($account, $variant, 'B2BVAL-LOCAL-1');

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, app(ConnectorHttpTransport::class));
        $this->assertSame(4, $transport->sendCount);
    }

    #[Test]
    public function transport_loss_after_delegate_maps_to_unknown_or_ambiguous_and_no_retry(): void
    {
        $transport = $this->bindTransport(fn (ConnectorOutboundRequest $request): ConnectorHttpResult => $this->baselineResponder($request));
        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-LOSS-1');
        $this->createTrustedVariantLink($account, $variant, 111);

        $exitCode = Artisan::call('adobe:stage3e-validate', [
            'connector-account-id' => $account->id,
            'product-variant-id' => (string) $variant->id,
            '--expect-host' => 'shop.example.com',
            '--execute-real-writes' => true,
            '--ack-write-sku' => 'B2BVAL-LOSS-1',
            '--simulate-transport-loss-after-write' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(4, $transport->sendCount);
        $puts = array_values(array_filter(
            $transport->recordedRequests,
            static fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'PUT',
        ));
        $this->assertCount(1, $puts);

        $artifact = $this->readArtifactFromOutput(Artisan::output());
        $this->assertSame('PASS', $artifact['result']);
        $this->assertSame('transport_loss_after_write', $artifact['scenario_code']);
        $this->assertSame('unknown_or_ambiguous', $artifact['scenario_events'][1]['applied_state']);
    }

    #[Test]
    public function ambiguous_original_write_forbids_restore_and_stops_without_retry(): void
    {
        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request, int $count): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/safe-sync/handshake')) {
                return $this->handshakeResult();
            }

            if ($request->request->getMethod() === 'GET') {
                return $this->productReadResult(112, 'B2BVAL-AMB-1', 'Original Name');
            }

            throw new ConnectorTransportException(TransportFailureReason::Timeout);
        });

        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-AMB-1');
        $this->createTrustedVariantLink($account, $variant, 112);

        $exitCode = Artisan::call('adobe:stage3e-validate', [
            'connector-account-id' => $account->id,
            'product-variant-id' => (string) $variant->id,
            '--expect-host' => 'shop.example.com',
            '--execute-real-writes' => true,
            '--ack-write-sku' => 'B2BVAL-AMB-1',
            '--restore-after-known-applied' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $puts = array_values(array_filter(
            $transport->recordedRequests,
            static fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'PUT',
        ));
        $this->assertCount(1, $puts);

        $artifact = $this->readArtifactFromOutput(Artisan::output());
        $this->assertSame('INCONCLUSIVE', $artifact['result']);
        $this->assertContains('baseline_write_ambiguous', $artifact['failure_codes']);
    }

    #[Test]
    public function known_applied_baseline_can_perform_one_bounded_restore(): void
    {
        $currentName = 'Original Name';

        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request) use (&$currentName): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/safe-sync/handshake')) {
                return $this->handshakeResult();
            }

            if ($request->request->getMethod() === 'GET') {
                return $this->productReadResult(113, 'B2BVAL-RESTORE-1', $currentName);
            }

            $payload = json_decode((string) $request->request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $currentName = (string) $payload['request']['name'];

            return $this->writeAppliedResult(113, 'B2BVAL-RESTORE-1');
        });

        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-RESTORE-1');
        $this->createTrustedVariantLink($account, $variant, 113);

        $exitCode = Artisan::call('adobe:stage3e-validate', [
            'connector-account-id' => $account->id,
            'product-variant-id' => (string) $variant->id,
            '--expect-host' => 'shop.example.com',
            '--execute-real-writes' => true,
            '--ack-write-sku' => 'B2BVAL-RESTORE-1',
            '--restore-after-known-applied' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $puts = array_values(array_filter(
            $transport->recordedRequests,
            static fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'PUT',
        ));
        $this->assertCount(2, $puts);

        $artifact = $this->readArtifactFromOutput(Artisan::output());
        $this->assertSame('PASS', $artifact['result']);
    }

    #[Test]
    public function ambiguous_restore_stops_after_one_bounded_restore_attempt(): void
    {
        $currentName = 'Original Name';
        $putCount = 0;

        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request) use (&$currentName, &$putCount): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/safe-sync/handshake')) {
                return $this->handshakeResult();
            }

            if ($request->request->getMethod() === 'GET') {
                return $this->productReadResult(114, 'B2BVAL-RESTORE-2', $currentName);
            }

            $putCount++;
            if ($putCount === 1) {
                $payload = json_decode((string) $request->request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                $currentName = (string) $payload['request']['name'];

                return $this->writeAppliedResult(114, 'B2BVAL-RESTORE-2');
            }

            throw new ConnectorTransportException(TransportFailureReason::Timeout);
        });

        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-RESTORE-2');
        $this->createTrustedVariantLink($account, $variant, 114);

        $exitCode = Artisan::call('adobe:stage3e-validate', [
            'connector-account-id' => $account->id,
            'product-variant-id' => (string) $variant->id,
            '--expect-host' => 'shop.example.com',
            '--execute-real-writes' => true,
            '--ack-write-sku' => 'B2BVAL-RESTORE-2',
            '--restore-after-known-applied' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $puts = array_values(array_filter(
            $transport->recordedRequests,
            static fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'PUT',
        ));
        $this->assertCount(2, $puts);

        $artifact = $this->readArtifactFromOutput(Artisan::output());
        $this->assertSame('INCONCLUSIVE', $artifact['result']);
        $this->assertContains('restore_write_ambiguous', $artifact['failure_codes']);
    }

    #[Test]
    public function evidence_and_console_output_remain_sanitized(): void
    {
        $transport = $this->bindTransport(fn (ConnectorOutboundRequest $request): ConnectorHttpResult => $this->baselineResponder($request));
        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-SAFE-1');
        $this->createTrustedVariantLink($account, $variant, 115);

        $exitCode = $this->runCommand($account, $variant, 'B2BVAL-SAFE-1');

        $this->assertSame(0, $exitCode);
        $artifactPath = $this->artifactPathFromOutput(Artisan::output());
        $contents = file_get_contents($artifactPath);
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('Authorization', $contents);
        $this->assertStringNotContainsString('ck_live', $contents);
        $this->assertStringNotContainsString('cs_live', $contents);
        $this->assertStringNotContainsString('at_live', $contents);
        $this->assertStringNotContainsString('ts_live', $contents);
        $this->assertStringNotContainsString('"request"', $contents);
        $this->assertStringNotContainsString('"body"', $contents);
        $this->assertStringNotContainsString('oauth', strtolower($contents));
        $this->assertStringNotContainsString('Authorization', Artisan::output());
        $this->assertGreaterThan(0, $transport->sendCount);
    }

    #[Test]
    public function rejected_handshake_admission_still_emits_sanitized_evidence(): void
    {
        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/safe-sync/handshake')) {
                return $this->handshakeResult(moduleVersion: '0.2.0');
            }

            return $this->productReadResult(118, 'B2BVAL-EVID-1', 'Original Name');
        });
        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-EVID-1');
        $this->createTrustedVariantLink($account, $variant, 118);

        $exitCode = $this->runCommand($account, $variant, 'B2BVAL-EVID-1');

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, $transport->sendCount);
        $artifact = $this->readArtifactFromOutput(Artisan::output());
        $this->assertSame('FAIL', $artifact['result']);
        $this->assertContains('safe_sync_module_version_below_s2_minimum', $artifact['failure_codes']);
        $this->assertSame('stage3e-r1', $artifact['contract_version']);
        $this->assertSame('0.2.0', $artifact['module_version']);
        $this->assertSame([
            'entity_bound_product_read',
            'entity_bound_simple_product_write',
        ], $artifact['supported_operation_families']);

        $contents = file_get_contents($this->artifactPathFromOutput(Artisan::output()));
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('Authorization', $contents);
        $this->assertStringNotContainsString('ck_live', $contents);
        $this->assertStringNotContainsString('"request"', $contents);
        $this->assertStringNotContainsString('"body"', $contents);
    }

    #[Test]
    public function accepted_handshake_artifact_records_contract_module_and_supported_operation_families(): void
    {
        $transport = $this->bindTransport(fn (ConnectorOutboundRequest $request): ConnectorHttpResult => $this->baselineResponder($request));
        $account = $this->createConnectorAccount();
        [$_, $variant] = $this->createValidationVariant($account->workspace, 'B2BVAL-HSHAKE-1');
        $this->createTrustedVariantLink($account, $variant, 119);

        $exitCode = $this->runCommand($account, $variant, 'B2BVAL-HSHAKE-1');

        $this->assertSame(0, $exitCode);
        $artifact = $this->readArtifactFromOutput(Artisan::output());
        $this->assertSame('stage3e-r1', $artifact['contract_version']);
        $this->assertSame('0.2.1', $artifact['module_version']);
        $this->assertSame([
            'entity_bound_product_read',
            'entity_bound_simple_product_write',
        ], $artifact['supported_operation_families']);
    }

    private function runCommand($account, ProductVariant $variant, string $ackSku): int
    {
        return Artisan::call('adobe:stage3e-validate', [
            'connector-account-id' => $account->id,
            'product-variant-id' => (string) $variant->id,
            '--expect-host' => 'shop.example.com',
            '--execute-real-writes' => true,
            '--ack-write-sku' => $ackSku,
        ]);
    }

    private function bindTransport(?\Closure $responder = null): RecordingConnectorHttpTransport
    {
        $transport = new RecordingConnectorHttpTransport(
            $responder ?? fn (ConnectorOutboundRequest $request): ConnectorHttpResult => $this->baselineResponder($request),
        );

        $this->app->instance(ConnectorHttpTransport::class, $transport);

        return $transport;
    }

    private function createValidationVariant(Workspace $workspace, string $sku): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku.'-P',
            'name' => 'Validation Product '.$sku,
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'is_active' => true,
        ]);

        return [$product, $variant];
    }

    private function createTrustedVariantLink($account, ProductVariant $variant, int $logicalEntityId, ?string $externalIdentifier = null): ExternalRecordLink
    {
        return ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $account->workspace,
                $account->id,
                $variant,
                $externalIdentifier ?? (string) $variant->sku,
                (string) $logicalEntityId,
                $this->createWorkspaceActor($account->workspace),
            ),
        );
    }

    /**
     * @param  list<string>|null  $supportedOperationFamilies
     */
    private function handshakeResult(
        string $moduleVersion = '0.2.1',
        ?array $supportedOperationFamilies = null,
    ): ConnectorHttpResult {
        return new ConnectorHttpResult(200, [], json_encode([
            'contract_version' => 'stage3e-r1',
            'module_version' => $moduleVersion,
            'supported_operation_families' => $supportedOperationFamilies ?? [
                'entity_bound_product_read',
                'entity_bound_simple_product_write',
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function productReadResult(int $logicalEntityId, string $sku, string $name): ConnectorHttpResult
    {
        return new ConnectorHttpResult(200, [], json_encode([
            'logical_entity_id' => $logicalEntityId,
            'sku' => $sku,
            'type_id' => 'simple',
            'name' => $name,
        ], JSON_THROW_ON_ERROR));
    }

    private function writeAppliedResult(int $logicalEntityId, string $sku): ConnectorHttpResult
    {
        return new ConnectorHttpResult(200, [], json_encode([
            'logical_entity_id' => $logicalEntityId,
            'sku' => $sku,
            'reason_code' => 'safe_sync_simple_product_write_applied',
            'applied_state' => 'known_applied',
            'postcondition_verified' => true,
            'consequential_write_attempts' => 1,
            'warning_codes' => [],
        ], JSON_THROW_ON_ERROR));
    }

    private function baselineResponder(ConnectorOutboundRequest $request): ConnectorHttpResult
    {
        static $names = [];

        $uri = (string) $request->request->getUri();

        if (str_contains($uri, '/safe-sync/handshake')) {
            return $this->handshakeResult();
        }

        if ($request->request->getMethod() === 'GET') {
            preg_match('#products/([1-9][0-9]*)#', $uri, $matches);
            $logicalEntityId = isset($matches[1]) ? (int) $matches[1] : 0;
            parse_str((string) $request->request->getUri()->getQuery(), $query);
            $sku = (string) ($query['expectedSku'] ?? 'B2BVAL-SKU');
            $name = $names[$logicalEntityId] ?? 'Original Name';

            return $this->productReadResult($logicalEntityId, $sku, $name);
        }

        $payload = json_decode((string) $request->request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        preg_match('#products/([1-9][0-9]*)#', $uri, $matches);
        $logicalEntityId = isset($matches[1]) ? (int) $matches[1] : 0;
        $name = (string) $payload['request']['name'];
        $sku = (string) $payload['request']['expected_sku'];
        $names[$logicalEntityId] = $name;

        return $this->writeAppliedResult($logicalEntityId, $sku);
    }

    /**
     * @return array<string, mixed>
     */
    private function readArtifactFromOutput(string $output): array
    {
        $path = $this->artifactPathFromOutput($output);

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function artifactPathFromOutput(string $output): string
    {
        preg_match('/^Evidence:\s+(.+)$/m', $output, $matches);

        if (! empty($matches[1] ?? null)) {
            return trim($matches[1]);
        }

        $artifactDirectory = trim((string) config('adobe_stage3e_validation.artifact_directory', 'stage3e-validation'), '/');
        $artifacts = Storage::disk('local')->files($artifactDirectory);

        $this->assertNotEmpty($artifacts, 'Artifact path was not present in command output and no validation artifact was written.');

        sort($artifacts);

        return Storage::disk('local')->path((string) last($artifacts));
    }
}
