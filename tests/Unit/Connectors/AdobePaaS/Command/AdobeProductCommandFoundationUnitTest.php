<?php

namespace Tests\Unit\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\Command\AdobeProductCommandCompilationException;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredStateCompiler;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductObservedState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteGetClassification;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteGetClassifier;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateComparator;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateNormalizer;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeProductCommandTestFixtures;
use Tests\TestCase;

class AdobeProductCommandFoundationUnitTest extends TestCase
{
    private AdobeProductDesiredStateCompiler $compiler;

    private AdobeProductRemoteGetClassifier $classifier;

    private AdobeProductRemoteStateComparator $comparator;

    protected function setUp(): void
    {
        parent::setUp();

        $normalizer = new AdobeProductRemoteStateNormalizer;
        $this->compiler = new AdobeProductDesiredStateCompiler;
        $this->classifier = new AdobeProductRemoteGetClassifier($normalizer);
        $this->comparator = new AdobeProductRemoteStateComparator;
    }

    #[Test]
    public function blocking_semantic_result_cannot_compile_desired_state(): void
    {
        $this->expectException(AdobeProductCommandCompilationException::class);

        $this->compiler->compileFromSemanticResult(AdobeProductCommandTestFixtures::blockingSemanticResult());
    }

    #[Test]
    public function compiler_uses_effective_net_price_not_gross_price(): void
    {
        $result = AdobeProductCommandTestFixtures::semanticResult([
            'resolved_price' => [
                'effective_net_price' => 80.0,
                'gross_price' => 96.0,
                'currency' => 'UAH',
                'vat_rate' => 20.0,
                'source' => 'base_price_cache',
            ],
        ]);

        $desired = $this->compiler->compileFromSemanticResult($result);

        $this->assertSame(80.0, $desired->price);
        $this->assertSame('UAH', $desired->priceCurrency);
    }

    #[Test]
    public function mapped_custom_values_do_not_override_connector_owned_top_level_fields(): void
    {
        $result = AdobeProductCommandTestFixtures::semanticResult([
            'mapped_variant_values' => [
                'binding-sku' => [
                    'internal_code' => 'sku',
                    'internal_value' => 'OVERRIDE-SKU',
                    'external_value' => 'OVERRIDE-SKU',
                ],
                'binding-custom' => [
                    'internal_code' => 'description',
                    'internal_value' => 'Custom text',
                    'external_value' => 'Custom text',
                ],
            ],
        ]);

        $desired = $this->compiler->compileFromSemanticResult($result, [
            ['field_binding_id' => 'binding-sku', 'external_field_key' => 'sku'],
            ['field_binding_id' => 'binding-custom', 'external_field_key' => 'description'],
        ]);

        $this->assertSame('SKU-TEST-1', $desired->sku);
        $this->assertSame(['description' => 'Custom text'], $desired->customAttributes);
    }

    #[Test]
    public function valid_product_get_is_classified_as_found(): void
    {
        $payload = AdobeProductCommandTestFixtures::remoteProductPayload();
        $result = $this->classifier->classify(
            'SKU-TEST-1',
            new ConnectorHttpResult(200, [], json_encode($payload, JSON_THROW_ON_ERROR)),
        );

        $this->assertSame(AdobeProductRemoteGetClassification::Found, $result->classification);
        $this->assertNotNull($result->observedState);
    }

    #[Test]
    public function verified_product_not_found_response_is_trusted_known_missing(): void
    {
        $result = $this->classifier->classify(
            'SKU-MISSING',
            new ConnectorHttpResult(
                404,
                [],
                AdobeProductCommandTestFixtures::trustedMissing404Body('SKU-MISSING'),
            ),
        );

        $this->assertSame(AdobeProductRemoteGetClassification::TrustedKnownMissing, $result->classification);
    }

    #[Test]
    public function generic_404_is_not_trusted_missing(): void
    {
        $result = $this->classifier->classify(
            'SKU-MISSING',
            new ConnectorHttpResult(404, [], '{"message":"Request does not match any route."}'),
        );

        $this->assertSame(AdobeProductRemoteGetClassification::UntrustedOrFailed, $result->classification);
    }

    #[Test]
    public function transport_and_server_failures_are_never_trusted_missing(): void
    {
        $transport = $this->classifier->classify(
            'SKU-1',
            null,
            new ConnectorTransportException(TransportFailureReason::Timeout),
        );
        $server = $this->classifier->classify(
            'SKU-1',
            new ConnectorHttpResult(500, [], '{"message":"Server error"}'),
        );
        $rateLimited = $this->classifier->classify(
            'SKU-1',
            new ConnectorHttpResult(429, [], '{"message":"Too many requests"}'),
        );

        $this->assertSame(AdobeProductRemoteGetClassification::UntrustedOrFailed, $transport->classification);
        $this->assertSame(AdobeProductRemoteGetClassification::UntrustedOrFailed, $server->classification);
        $this->assertSame(AdobeProductRemoteGetClassification::UntrustedOrFailed, $rateLimited->classification);
    }

    #[Test]
    public function comparator_detects_controlled_state_differences_deterministically(): void
    {
        $desired = new AdobeProductDesiredState(
            productVariantId: 'variant-1',
            sku: 'SKU-1',
            name: 'Name',
            attributeSetId: 4,
            typeId: 'simple',
            status: 1,
            visibility: 4,
            price: 100.0,
            priceCurrency: 'UAH',
            customAttributes: ['description' => 'A'],
        );

        $matching = new AdobeProductObservedState(
            sku: 'SKU-1',
            name: 'Name',
            attributeSetId: 4,
            typeId: 'simple',
            status: 1,
            visibility: 4,
            price: 100.0,
            customAttributes: ['description' => 'A'],
        );

        $different = new AdobeProductObservedState(
            sku: 'SKU-1',
            name: 'Different',
            attributeSetId: 4,
            typeId: 'simple',
            status: 1,
            visibility: 4,
            price: 100.0,
            customAttributes: ['description' => 'A'],
        );

        $this->assertTrue($this->comparator->controlledStateMatches($desired, $matching));
        $this->assertFalse($this->comparator->controlledStateMatches($desired, $different));
    }
}
