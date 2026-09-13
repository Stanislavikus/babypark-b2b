<?php

namespace Tests\Feature\Connectors;

use App\Models\AdobeProductAttributeLineage;
use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetMembership;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorSchemaSource;
use App\Models\Workspace;
use Database\Seeders\ConnectorFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdobeProductAttributeStructureFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_structure_tables_expose_only_provider_structure_state(): void
    {
        $this->assertTrue(Schema::hasColumns('adobe_product_attribute_lineages', [
            'provider_attribute_id', 'current_external_field_key', 'last_external_field_key',
            'first_seen_at', 'last_seen_at', 'missing_since',
        ]));
        $this->assertTrue(Schema::hasColumns('adobe_product_attribute_sets', [
            'provider_attribute_set_id', 'name', 'first_seen_at', 'last_seen_at', 'missing_since',
        ]));
        $this->assertTrue(Schema::hasColumns('adobe_product_attribute_groups', [
            'adobe_product_attribute_set_id', 'provider_attribute_group_id', 'name',
        ]));
        $this->assertTrue(Schema::hasColumns('adobe_product_attribute_set_memberships', [
            'adobe_product_attribute_lineage_id', 'adobe_product_attribute_set_id', 'missing_since',
        ]));
        $this->assertTrue(Schema::hasColumns('adobe_product_attribute_option_lineages', [
            'adobe_product_attribute_lineage_id', 'provider_option_id', 'default_label', 'labels_by_store',
        ]));

        foreach ([
            'adobe_product_attribute_lineages',
            'adobe_product_attribute_sets',
            'adobe_product_attribute_groups',
            'adobe_product_attribute_set_memberships',
            'adobe_product_attribute_option_lineages',
        ] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'mapping_readiness'));
            $this->assertFalse(Schema::hasColumn($table, 'field_group'));
        }
    }

    public function test_provider_attribute_id_survives_code_rename(): void
    {
        [$account, $source] = $this->context();
        $lineage = $this->lineage($account, $source, 149, 'merchant_old');

        $lineage->update([
            'last_external_field_key' => 'merchant_new',
            'last_seen_at' => now()->addMinute(),
        ]);

        $reloaded = AdobeProductAttributeLineage::withoutWorkspaceScope()->findOrFail($lineage->id);
        $this->assertSame(149, $reloaded->provider_attribute_id);
        $this->assertSame('merchant_new', $reloaded->current_external_field_key);
        $this->assertSame($lineage->id, $reloaded->id);
    }

    public function test_active_code_is_unique_but_delete_readd_with_new_provider_id_is_allowed(): void
    {
        [$account, $source] = $this->context();
        $first = $this->lineage($account, $source, 201, 'merchant_code');

        try {
            $this->lineage($account, $source, 202, 'merchant_code');
            $this->fail('Two active lineages with one external code must be rejected.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $first->update([
            'missing_since' => now(),
        ]);

        $second = $this->lineage($account, $source, 202, 'merchant_code');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(202, $second->provider_attribute_id);
    }

    public function test_membership_cannot_cross_connector_account_context(): void
    {
        [$accountA, $source] = $this->context();
        $definition = ConnectorDefinition::query()->where('code', 'adobe_commerce')->firstOrFail();
        $accountB = ConnectorAccount::factory()->create([
            'workspace_id' => $accountA->workspace_id,
            'connector_definition_id' => $definition->id,
        ]);

        $lineageA = $this->lineage($accountA, $source, 301, 'merchant_a');
        $setB = $this->attributeSet($accountB, $source, 9, 'Foreign Set');

        $this->expectException(QueryException::class);
        AdobeProductAttributeSetMembership::withoutWorkspaceScope()->create([
            'workspace_id' => $accountA->workspace_id,
            'connector_account_id' => $accountA->id,
            'connector_schema_source_id' => $source->id,
            'adobe_product_attribute_lineage_id' => $lineageA->id,
            'adobe_product_attribute_set_id' => $setB->id,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /** @return array{ConnectorAccount, ConnectorSchemaSource} */
    private function context(): array
    {
        $this->seed(ConnectorFoundationSeeder::class);
        $workspace = Workspace::query()->where('is_default', true)->first()
            ?? Workspace::query()->create(['name' => 'Stage 2 Workspace', 'is_default' => true]);
        $definition = ConnectorDefinition::query()->where('code', 'adobe_commerce')->firstOrFail();
        $account = ConnectorAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'connector_definition_id' => $definition->id,
        ]);
        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $definition->id)
            ->where('code', 'live_account_attributes')
            ->firstOrFail();

        return [$account, $source];
    }

    private function lineage(
        ConnectorAccount $account,
        ConnectorSchemaSource $source,
        int $providerId,
        string $code,
    ): AdobeProductAttributeLineage {
        return AdobeProductAttributeLineage::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'provider_attribute_id' => $providerId,
            'last_external_field_key' => $code,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function attributeSet(
        ConnectorAccount $account,
        ConnectorSchemaSource $source,
        int $providerId,
        string $name,
    ): AdobeProductAttributeSet {
        return AdobeProductAttributeSet::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'provider_attribute_set_id' => $providerId,
            'name' => $name,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
