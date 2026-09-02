<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorComponentReadiness;
use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Services\Connectors\AdobeSafeSyncComponentReadinessResolver;
use App\Support\Connectors\AdobePaaS\AdobeMagentoVersionProbeCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncContract;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncHandshake;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncHandshakeProbeCapability;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncHandshakeProbeResult;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncReadinessResult;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncRequiredOperation;
use App\Support\Connectors\ConnectorConnectionCheckResult;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class AdobeSafeSyncComponentReadinessResolverTest extends TestCase
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
    public function exact_handshake_404_after_healthy_baseline_requires_setup(): void
    {
        $result = $this->resolve(
            ConnectorConnectionCheckResult::success(),
            AdobeSafeSyncHandshakeProbeResult::failed(ConnectorConnectionCheckResult::httpFailure(
                ConnectorConnectionCheckErrorCode::AdobeInvalidOrUnsupportedEndpoint,
                404,
            )),
        );

        $this->assertSame(ConnectorComponentReadiness::SetupRequired, $result->componentReadiness);
        $this->assertSame(404, $result->connectionResult->httpStatus);
        $this->assertTrue($result->baselineSucceeded);
    }

    #[Test]
    public function baseline_success_carries_stock_magento_version_evidence_without_blocking_handshake_logic(): void
    {
        $result = $this->resolve(
            ConnectorConnectionCheckResult::success(),
            AdobeSafeSyncHandshakeProbeResult::failed(ConnectorConnectionCheckResult::httpFailure(
                ConnectorConnectionCheckErrorCode::AdobeInvalidOrUnsupportedEndpoint,
                404,
            )),
            stockMagentoVersionEvidence: 'Magento/2.4 (Community)',
        );

        $this->assertSame('Magento/2.4 (Community)', $result->stockMagentoVersionEvidence);
        $this->assertSame(ConnectorComponentReadiness::SetupRequired, $result->componentReadiness);
    }

    #[Test]
    public function endpoint_error_405_does_not_fabricate_setup_required(): void
    {
        $result = $this->resolve(
            ConnectorConnectionCheckResult::success(),
            AdobeSafeSyncHandshakeProbeResult::failed(ConnectorConnectionCheckResult::httpFailure(
                ConnectorConnectionCheckErrorCode::AdobeInvalidOrUnsupportedEndpoint,
                405,
            )),
        );

        $this->assertNull($result->componentReadiness);
        $this->assertTrue($result->baselineSucceeded);
    }

    #[Test]
    public function failed_baseline_stops_without_invoking_handshake(): void
    {
        foreach ([
            ConnectorConnectionCheckResult::httpFailure(ConnectorConnectionCheckErrorCode::AdobeInvalidCredentials, 401),
            ConnectorConnectionCheckResult::transportFailure(ConnectorConnectionCheckErrorCode::TransportConnectionFailed),
        ] as $baseline) {
            $result = $this->resolve($baseline, null);
            $this->assertNull($result->componentReadiness);
            $this->assertSame($baseline->errorCode, $result->connectionResult->errorCode);
            $this->assertFalse($result->baselineSucceeded);
        }
    }

    #[Test]
    public function readiness_is_scoped_to_required_operation_families(): void
    {
        $readOnly = AdobeSafeSyncHandshakeProbeResult::succeeded(new AdobeSafeSyncHandshake(
            AdobeSafeSyncContract::CONTRACT_VERSION,
            '0.2.1',
            [AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY, 'future_additive_family'],
        ));

        $this->assertSame(ConnectorComponentReadiness::Ready, $this->resolve(
            ConnectorConnectionCheckResult::success(), $readOnly, AdobeSafeSyncRequiredOperation::ProductRead,
        )->componentReadiness);
        $this->assertSame(ConnectorComponentReadiness::UpdateRequired, $this->resolve(
            ConnectorConnectionCheckResult::success(), $readOnly, AdobeSafeSyncRequiredOperation::SimpleProductWrite,
        )->componentReadiness);

        $readWrite = AdobeSafeSyncHandshakeProbeResult::succeeded(new AdobeSafeSyncHandshake(
            AdobeSafeSyncContract::CONTRACT_VERSION,
            '0.2.1',
            [AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY, AdobeSafeSyncContract::SIMPLE_PRODUCT_WRITE_FAMILY],
        ));
        $this->assertSame(ConnectorComponentReadiness::Ready, $this->resolve(
            ConnectorConnectionCheckResult::success(), $readWrite,
        )->componentReadiness);
    }

    #[Test]
    public function handshake_environment_versions_are_optional_and_propagate_when_present(): void
    {
        $probe = AdobeSafeSyncHandshakeProbeResult::succeeded(new AdobeSafeSyncHandshake(
            AdobeSafeSyncContract::CONTRACT_VERSION,
            '0.2.1',
            [AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY, AdobeSafeSyncContract::SIMPLE_PRODUCT_WRITE_FAMILY],
            '2.4.7-p1',
            '8.3.10',
        ));

        $result = $this->resolve(ConnectorConnectionCheckResult::success(), $probe);

        $this->assertSame('2.4.7-p1', $result->applicationVersion);
        $this->assertSame('8.3.10', $result->phpVersion);
    }

    #[Test]
    public function unsupported_epoch_requires_compatible_component_replacement(): void
    {
        $probe = AdobeSafeSyncHandshakeProbeResult::succeeded(new AdobeSafeSyncHandshake(
            'breaking-r2', '9.0.0', [AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY],
        ));

        $this->assertSame(ConnectorComponentReadiness::UpdateRequired, $this->resolve(
            ConnectorConnectionCheckResult::success(), $probe, AdobeSafeSyncRequiredOperation::ProductRead,
        )->componentReadiness);
    }

    #[Test]
    public function simple_write_readiness_uses_the_validation_minimum_without_imposing_it_on_read(): void
    {
        foreach ([
            '0.2.0' => ConnectorComponentReadiness::UpdateRequired,
            '0.2.1' => ConnectorComponentReadiness::Ready,
            '0.2.2' => ConnectorComponentReadiness::Ready,
            'dev-main' => ConnectorComponentReadiness::UpdateRequired,
        ] as $moduleVersion => $expected) {
            $probe = AdobeSafeSyncHandshakeProbeResult::succeeded(new AdobeSafeSyncHandshake(
                AdobeSafeSyncContract::CONTRACT_VERSION,
                $moduleVersion,
                [AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY, AdobeSafeSyncContract::SIMPLE_PRODUCT_WRITE_FAMILY],
            ));

            $this->assertSame($expected, $this->resolve(
                ConnectorConnectionCheckResult::success(),
                $probe,
                AdobeSafeSyncRequiredOperation::SimpleProductWrite,
            )->componentReadiness, $moduleVersion);
        }

        $legacyRead = AdobeSafeSyncHandshakeProbeResult::succeeded(new AdobeSafeSyncHandshake(
            AdobeSafeSyncContract::CONTRACT_VERSION,
            '0.1.0',
            [AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY],
        ));

        $this->assertSame(ConnectorComponentReadiness::Ready, $this->resolve(
            ConnectorConnectionCheckResult::success(),
            $legacyRead,
            AdobeSafeSyncRequiredOperation::ProductRead,
        )->componentReadiness);
    }

    #[Test]
    public function validation_runner_and_readiness_share_the_simple_write_minimum_constant(): void
    {
        $runner = file_get_contents(app_path('Support/Connectors/AdobePaaS/Validation/AdobeStage3EValidationRunner.php'));
        $resolver = file_get_contents(app_path('Services/Connectors/AdobeSafeSyncComponentReadinessResolver.php'));

        $this->assertSame('0.2.1', AdobeSafeSyncContract::SIMPLE_PRODUCT_WRITE_MINIMUM_MODULE_VERSION);
        $this->assertStringContainsString('AdobeSafeSyncContract::SIMPLE_PRODUCT_WRITE_MINIMUM_MODULE_VERSION', $runner);
        $this->assertStringContainsString('AdobeSafeSyncContract::SIMPLE_PRODUCT_WRITE_MINIMUM_MODULE_VERSION', $resolver);
        $this->assertStringNotContainsString("version_compare(\$handshake->moduleVersion, '0.2.1'", $runner);
    }

    private function resolve(
        ConnectorConnectionCheckResult $baseline,
        ?AdobeSafeSyncHandshakeProbeResult $probe,
        AdobeSafeSyncRequiredOperation $operation = AdobeSafeSyncRequiredOperation::SimpleProductWrite,
        ?string $stockMagentoVersionEvidence = null,
    ): AdobeSafeSyncReadinessResult {
        $account = $this->createConnectorAccount();
        $calls = (object) ['handshake' => 0];

        $this->app->instance(AdobePaaSConnectionCheckCapability::class, new class($baseline) implements AdobePaaSConnectionCheckCapability
        {
            public function __construct(private ConnectorConnectionCheckResult $result) {}

            public function checkConnection(AdobePaaSRequestContext $context): ConnectorConnectionCheckResult
            {
                return $this->result;
            }
        });
        $this->app->instance(AdobeMagentoVersionProbeCapability::class, new class($stockMagentoVersionEvidence) implements AdobeMagentoVersionProbeCapability
        {
            public function __construct(private ?string $result) {}

            public function probe(AdobePaaSRequestContext $context): ?string
            {
                return $this->result;
            }
        });
        $this->app->instance(AdobeSafeSyncHandshakeProbeCapability::class, new class($probe, $calls) implements AdobeSafeSyncHandshakeProbeCapability
        {
            public function __construct(private ?AdobeSafeSyncHandshakeProbeResult $result, private object $calls) {}

            public function probe(AdobePaaSRequestContext $context): AdobeSafeSyncHandshakeProbeResult
            {
                $this->calls->handshake++;
                if ($this->result === null) {
                    throw new \LogicException('Handshake must not be invoked.');
                }

                return $this->result;
            }
        });

        return app(AdobeSafeSyncComponentReadinessResolver::class)->resolve($account->workspace_id, $account->id, $operation);
    }
}
