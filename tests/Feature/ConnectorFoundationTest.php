<?php

namespace Tests\Feature;

use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaVerificationStatus;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorSchemaSource;
use App\Services\Connectors\ConnectorSchemaSourceService;
use Database\Seeders\ConnectorFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorFoundationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeder_is_idempotent_and_does_not_overwrite_existing_sources(): void
    {
        $this->seed(ConnectorFoundationSeeder::class);

        $adobe = ConnectorDefinition::query()->where('code', 'adobe_commerce')->firstOrFail();
        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $adobe->id)
            ->where('code', 'admin_rest_api')
            ->firstOrFail();

        $source->update([
            'notes' => 'admin edited',
            'reference_url' => 'https://example.com/custom',
            'verification_status' => ConnectorSchemaVerificationStatus::Stale,
        ]);

        $this->seed(ConnectorFoundationSeeder::class);

        $source->refresh();

        $this->assertSame('admin edited', $source->notes);
        $this->assertSame('https://example.com/custom', $source->reference_url);
        $this->assertSame(ConnectorSchemaVerificationStatus::Stale, $source->verification_status);
    }

    #[Test]
    public function setting_primary_unsets_previous_primary_in_same_scope(): void
    {
        $this->seed(ConnectorFoundationSeeder::class);

        $shopify = ConnectorDefinition::query()->where('code', 'shopify')->firstOrFail();

        $first = ConnectorSchemaSource::query()->create([
            'connector_definition_id' => $shopify->id,
            'code' => 'extra_global_source',
            'label' => 'Extra global',
            'source_kind' => 'official_web_doc',
            'acquisition_mode' => 'remote_static',
            'schema_scope' => ConnectorSchemaScope::Global,
            'reference_url' => 'https://example.com/extra',
            'is_primary' => false,
            'verification_status' => ConnectorSchemaVerificationStatus::Verified,
            'last_verified_at' => now(),
            'sort_order' => 99,
        ]);

        $existingPrimary = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $shopify->id)
            ->where('code', 'product_taxonomy_attributes')
            ->firstOrFail();

        app(ConnectorSchemaSourceService::class)->setPrimary($first, true);

        $existingPrimary->refresh();
        $first->refresh();

        $this->assertFalse($existingPrimary->is_primary);
        $this->assertTrue($first->is_primary);
    }

    #[Test]
    public function shopify_taxonomy_attributes_is_primary_not_graphql_taxonomy_value(): void
    {
        $this->seed(ConnectorFoundationSeeder::class);

        $shopify = ConnectorDefinition::query()->where('code', 'shopify')->firstOrFail();

        $attributes = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $shopify->id)
            ->where('code', 'product_taxonomy_attributes')
            ->firstOrFail();

        $graphql = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $shopify->id)
            ->where('code', 'admin_graphql_taxonomy_value')
            ->firstOrFail();

        $this->assertTrue($attributes->is_primary);
        $this->assertFalse($graphql->is_primary);
    }
}
