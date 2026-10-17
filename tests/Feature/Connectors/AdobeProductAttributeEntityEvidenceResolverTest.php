<?php

namespace Tests\Feature\Connectors;

use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Enums\FieldObjectType;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Connectors\AdobeProductAttributeEntityEvidenceResolver;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

final class AdobeProductAttributeEntityEvidenceResolverTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([WorkspaceSeeder::class, ConnectorFoundationSeeder::class]);
    }

    #[Test]
    public function platform_created_variant_contributes_trusted_attribute_entity_evidence(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'PLATFORM-EVIDENCE-PRODUCT',
            'name' => 'Platform Evidence Product',
            'is_active' => true,
        ]);
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'PLATFORM-EVIDENCE-SKU',
            'is_active' => true,
        ]);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => $variant->sku,
            'trust_origin' => ExternalRecordLinkTrustOrigin::PlatformCreated->value,
            'external_record_discriminator' => '8001',
            'established_by_workspace_user_id' => null,
            'established_at' => now(),
        ]);

        $transport = new RecordingConnectorHttpTransport(static fn (): ConnectorHttpResult => new ConnectorHttpResult(
            200,
            [],
            json_encode([
                'id' => 8001,
                'sku' => 'PLATFORM-EVIDENCE-SKU',
                'name' => 'Platform Evidence Product',
                'attribute_set_id' => 4,
                'type_id' => 'simple',
                'status' => 1,
                'visibility' => 1,
                'price' => 100,
                'custom_attributes' => [
                    ['attribute_code' => 'merchant_color', 'value' => '10'],
                ],
            ], JSON_THROW_ON_ERROR),
        ));
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $evidence = app(AdobeProductAttributeEntityEvidenceResolver::class)->resolve($account);

        $this->assertSame([FieldObjectType::ProductVariant], $evidence['merchant_color'] ?? null);
        $this->assertSame(1, $transport->sendCount);
    }
}
