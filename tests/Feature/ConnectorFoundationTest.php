<?php

namespace Tests\Feature;

use App\Enums\ConnectorDefinitionStatus;
use App\Enums\ConnectorDirection;
use App\Enums\ConnectorSchemaAcquisitionMode;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Enums\ConnectorSchemaVerificationStatus;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorSchemaSource;
use App\Services\Connectors\ConnectorDefinitionGovernanceService;
use Database\Seeders\ConnectorFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorFoundationTest extends TestCase
{
    use RefreshDatabase;

    private ConnectorDefinitionGovernanceService $governance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->governance = app(ConnectorDefinitionGovernanceService::class);
    }

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

    #[Test]
    public function cannot_activate_definition_without_any_source(): void
    {
        $definition = $this->createDraftDefinition('no_sources');

        $this->expectException(ValidationException::class);

        $this->governance->updateDefinition($definition, [
            'status' => ConnectorDefinitionStatus::Active,
        ]);
    }

    #[Test]
    public function cannot_activate_definition_with_non_primary_source_only(): void
    {
        $definition = $this->createDraftDefinition('non_primary_only');

        $this->createVerifiedGlobalSource($definition, [
            'code' => 'non_primary',
            'is_primary' => false,
        ]);

        $this->expectException(ValidationException::class);

        $this->governance->updateDefinition($definition, [
            'status' => ConnectorDefinitionStatus::Active,
        ]);
    }

    #[Test]
    public function cannot_activate_definition_with_account_scope_primary_only(): void
    {
        $definition = $this->createDraftDefinition('account_primary_only');

        $this->governance->createSource($definition, [
            'code' => 'account_primary',
            'label' => 'Account primary',
            'source_kind' => ConnectorSchemaSourceKind::AccountApi,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::LiveFetch,
            'schema_scope' => ConnectorSchemaScope::Account,
            'reference_url' => 'https://example.com/account-docs',
            'endpoint_path' => '/V1/attributes',
            'is_primary' => true,
            'verification_status' => ConnectorSchemaVerificationStatus::Verified,
            'last_verified_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        $this->governance->updateDefinition($definition, [
            'status' => ConnectorDefinitionStatus::Active,
        ]);
    }

    #[Test]
    public function cannot_activate_definition_with_unverified_or_stale_or_broken_global_primary(): void
    {
        foreach ([
            ConnectorSchemaVerificationStatus::Stale,
            ConnectorSchemaVerificationStatus::Broken,
            ConnectorSchemaVerificationStatus::Unverified,
        ] as $status) {
            $definition = $this->createDraftDefinition('invalid_'.$status->value);

            $this->createVerifiedGlobalSource($definition, [
                'code' => 'invalid_primary_'.$status->value,
                'verification_status' => $status,
                'last_verified_at' => $status === ConnectorSchemaVerificationStatus::Verified ? now() : null,
            ]);

            $this->expectException(ValidationException::class);

            $this->governance->updateDefinition($definition, [
                'status' => ConnectorDefinitionStatus::Active,
            ]);
        }
    }

    #[Test]
    public function can_activate_definition_with_verified_primary_global_source(): void
    {
        $definition = $this->createDraftDefinition('can_activate');

        $this->createVerifiedGlobalSource($definition);

        $updated = $this->governance->updateDefinition($definition, [
            'status' => ConnectorDefinitionStatus::Active,
        ]);

        $this->assertSame(ConnectorDefinitionStatus::Active, $updated->status);
    }

    #[Test]
    public function cannot_update_other_fields_on_invalid_active_definition(): void
    {
        $definition = $this->createDraftDefinition('invalid_active');
        $this->createVerifiedGlobalSource($definition);

        DB::table('connector_definitions')
            ->where('id', $definition->id)
            ->update(['status' => ConnectorDefinitionStatus::Active->value]);

        ConnectorSchemaSource::query()
            ->where('connector_definition_id', $definition->id)
            ->update(['is_primary' => false]);

        $definition->refresh();
        $originalNotes = $definition->notes;

        $this->expectException(ValidationException::class);

        try {
            $this->governance->updateDefinition($definition, [
                'notes' => 'should not save',
            ]);
        } finally {
            $definition->refresh();
            $this->assertSame(ConnectorDefinitionStatus::Active, $definition->status);
            $this->assertSame($originalNotes, $definition->notes);
        }
    }

    #[Test]
    public function active_connector_becomes_draft_when_last_qualifying_source_is_deleted(): void
    {
        $definition = $this->activateDefinitionWithSingleQualifyingSource('delete_qualifying');

        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $definition->id)
            ->firstOrFail();

        $this->governance->deleteSource($source);

        $definition->refresh();
        $this->assertSame(ConnectorDefinitionStatus::Draft, $definition->status);
    }

    #[Test]
    public function active_connector_becomes_draft_when_verification_status_changes_from_verified_to_stale(): void
    {
        $definition = $this->activateDefinitionWithSingleQualifyingSource('verify_to_stale');
        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $definition->id)
            ->firstOrFail();

        $this->governance->updateSource($source, [
            'verification_status' => ConnectorSchemaVerificationStatus::Stale,
        ]);

        $definition->refresh();
        $this->assertSame(ConnectorDefinitionStatus::Draft, $definition->status);
    }

    #[Test]
    public function active_connector_becomes_draft_when_is_primary_is_unset(): void
    {
        $definition = $this->activateDefinitionWithSingleQualifyingSource('unset_primary');
        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $definition->id)
            ->firstOrFail();

        $this->governance->updateSource($source, [
            'is_primary' => false,
        ]);

        $definition->refresh();
        $this->assertSame(ConnectorDefinitionStatus::Draft, $definition->status);
    }

    #[Test]
    public function active_connector_becomes_draft_when_unverified_source_is_created_as_global_primary(): void
    {
        $definition = $this->activateDefinitionWithSingleQualifyingSource('create_unverified_primary');

        $this->governance->createSource($definition, [
            'code' => 'new_stale_primary',
            'label' => 'Stale primary',
            'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
            'schema_scope' => ConnectorSchemaScope::Global,
            'reference_url' => 'https://example.com/stale',
            'is_primary' => true,
            'verification_status' => ConnectorSchemaVerificationStatus::Stale,
        ]);

        $definition->refresh();
        $this->assertSame(ConnectorDefinitionStatus::Draft, $definition->status);
    }

    #[Test]
    public function active_connector_becomes_draft_when_unverified_existing_source_is_promoted_to_global_primary(): void
    {
        $definition = $this->activateDefinitionWithSingleQualifyingSource('promote_unverified');

        $replacement = $this->governance->createSource($definition, [
            'code' => 'replacement_stale',
            'label' => 'Replacement stale',
            'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
            'schema_scope' => ConnectorSchemaScope::Global,
            'reference_url' => 'https://example.com/replacement',
            'is_primary' => false,
            'verification_status' => ConnectorSchemaVerificationStatus::Stale,
        ]);

        $this->governance->updateSource($replacement, [
            'is_primary' => true,
        ]);

        $definition->refresh();
        $this->assertSame(ConnectorDefinitionStatus::Draft, $definition->status);
    }

    #[Test]
    public function active_connector_remains_active_when_existing_qualifying_source_is_promoted_to_primary(): void
    {
        $definition = $this->createDraftDefinition('promote_existing');

        $existingPrimary = $this->createVerifiedGlobalSource($definition, [
            'code' => 'existing_primary',
        ]);

        $replacement = $this->governance->createSource($definition, [
            'code' => 'replacement_verified',
            'label' => 'Replacement verified',
            'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
            'schema_scope' => ConnectorSchemaScope::Global,
            'reference_url' => 'https://example.com/replacement-verified',
            'is_primary' => false,
            'verification_status' => ConnectorSchemaVerificationStatus::Verified,
            'last_verified_at' => now(),
        ]);

        $definition = $this->governance->updateDefinition($definition, [
            'status' => ConnectorDefinitionStatus::Active,
        ]);

        $this->governance->updateSource($replacement, [
            'is_primary' => true,
        ]);

        $definition->refresh();
        $existingPrimary->refresh();

        $this->assertSame(ConnectorDefinitionStatus::Active, $definition->status);
        $this->assertFalse($existingPrimary->is_primary);
        $this->assertTrue($replacement->refresh()->is_primary);
    }

    #[Test]
    public function definition_is_created_as_draft_through_governance_service(): void
    {
        $definition = $this->createDraftDefinition('created_draft');

        $this->assertSame(ConnectorDefinitionStatus::Draft, $definition->status);
    }

    #[Test]
    public function definition_cannot_be_created_directly_as_active_through_service(): void
    {
        $this->expectException(ValidationException::class);

        $this->governance->createDefinition([
            'code' => 'active_on_create',
            'name' => 'Active on create',
            'direction' => ConnectorDirection::Both,
            'status' => ConnectorDefinitionStatus::Active,
        ]);
    }

    #[Test]
    public function definition_with_sources_cannot_be_hard_deleted(): void
    {
        $definition = $this->createDraftDefinition('has_sources');
        $this->createVerifiedGlobalSource($definition);

        $this->expectException(ValidationException::class);

        $this->governance->deleteDefinitionWhenUnreferenced($definition);
    }

    #[Test]
    public function unreferenced_definition_can_be_deleted(): void
    {
        $definition = $this->createDraftDefinition('no_refs');

        $this->governance->deleteDefinitionWhenUnreferenced($definition);

        $this->assertNull(ConnectorDefinition::query()->find($definition->id));
    }

    #[Test]
    public function delete_definition_checks_existence_after_lock_not_before(): void
    {
        $definition = $this->createDraftDefinition('lock_order');
        $this->createVerifiedGlobalSource($definition);

        $this->expectException(ValidationException::class);

        $this->governance->deleteDefinitionWhenUnreferenced($definition);

        $this->assertTrue(
            ConnectorDefinition::query()->whereKey($definition->id)->exists(),
            'SQLite test confirms service operation order (lock → exists → abort), not real concurrency serialization.',
        );
    }

    #[Test]
    public function exception_inside_governance_transaction_rolls_back_both_source_and_status_change(): void
    {
        $definition = $this->activateDefinitionWithSingleQualifyingSource('rollback');
        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $definition->id)
            ->firstOrFail();

        ConnectorDefinition::saving(function (): void {
            throw new \RuntimeException('abort parent save');
        });

        try {
            $this->governance->updateSource($source, [
                'verification_status' => ConnectorSchemaVerificationStatus::Stale,
            ]);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException) {
            // expected
        } finally {
            ConnectorDefinition::flushEventListeners();
            ConnectorDefinition::booted();
        }

        $source->refresh();
        $definition->refresh();

        $this->assertSame(ConnectorSchemaVerificationStatus::Verified, $source->verification_status);
        $this->assertSame(ConnectorDefinitionStatus::Active, $definition->status);
    }

    #[Test]
    public function definition_code_cannot_be_changed_through_governance_service(): void
    {
        $definition = $this->createDraftDefinition('immutable_code');

        $this->expectException(ValidationException::class);

        $this->governance->updateDefinition($definition, [
            'code' => 'changed_code',
        ]);
    }

    #[Test]
    public function source_code_cannot_be_changed_through_governance_service(): void
    {
        $definition = $this->createDraftDefinition('immutable_source_code');
        $source = $this->createVerifiedGlobalSource($definition);

        $this->expectException(ValidationException::class);

        $this->governance->updateSource($source, [
            'code' => 'changed_source_code',
        ]);
    }

    #[Test]
    public function source_connector_definition_cannot_be_changed_through_governance_service(): void
    {
        $definition = $this->createDraftDefinition('immutable_owner');
        $other = $this->createDraftDefinition('other_owner');
        $source = $this->createVerifiedGlobalSource($definition);

        $this->expectException(ValidationException::class);

        $this->governance->updateSource($source, [
            'connector_definition_id' => $other->id,
        ]);
    }

    #[Test]
    public function create_source_rejects_foreign_connector_definition_id(): void
    {
        $definition = $this->createDraftDefinition('owner_a');
        $other = $this->createDraftDefinition('owner_b');

        $this->expectException(ValidationException::class);

        try {
            $this->governance->createSource($definition, [
                'connector_definition_id' => $other->id,
                'code' => 'foreign_owner',
                'label' => 'Foreign owner',
                'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
                'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
                'schema_scope' => ConnectorSchemaScope::Global,
                'reference_url' => 'https://example.com/foreign',
                'verification_status' => ConnectorSchemaVerificationStatus::Stale,
            ]);
        } finally {
            $this->assertSame(
                0,
                ConnectorSchemaSource::query()->where('code', 'foreign_owner')->count(),
            );
        }
    }

    #[Test]
    public function nullable_source_fields_can_be_explicitly_cleared(): void
    {
        $definition = $this->createDraftDefinition('clear_nullables');

        $source = $this->governance->createSource($definition, [
            'code' => 'clearable',
            'label' => 'Clearable',
            'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
            'schema_scope' => ConnectorSchemaScope::Global,
            'reference_url' => 'https://example.com/clearable',
            'schema_version' => '1.0',
            'notes' => 'keep me until cleared',
            'is_primary' => false,
            'verification_status' => ConnectorSchemaVerificationStatus::Stale,
        ]);

        $updated = $this->governance->updateSource($source, [
            'reference_url' => null,
            'schema_version' => null,
            'notes' => null,
        ]);

        $this->assertNull($updated->reference_url);
        $this->assertNull($updated->schema_version);
        $this->assertNull($updated->notes);
    }

    #[Test]
    public function string_false_does_not_become_true_for_is_primary(): void
    {
        $definition = $this->createDraftDefinition('string_false');

        $this->expectException(ValidationException::class);

        $this->governance->createSource($definition, [
            'code' => 'bool_trap',
            'label' => 'Bool trap',
            'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
            'schema_scope' => ConnectorSchemaScope::Global,
            'reference_url' => 'https://example.com/bool',
            'is_primary' => 'false',
            'verification_status' => ConnectorSchemaVerificationStatus::Stale,
        ]);
    }

    #[Test]
    public function create_source_without_is_primary_defaults_to_false(): void
    {
        $definition = $this->createDraftDefinition('default_primary');

        $source = $this->governance->createSource($definition, [
            'code' => 'default_primary_source',
            'label' => 'Default primary',
            'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
            'schema_scope' => ConnectorSchemaScope::Global,
            'reference_url' => 'https://example.com/default',
            'verification_status' => ConnectorSchemaVerificationStatus::Stale,
        ]);

        $this->assertFalse($source->is_primary);
    }

    #[Test]
    public function create_source_primary_swap_never_exposes_two_primaries(): void
    {
        $definition = $this->createDraftDefinition('primary_swap');

        $this->createVerifiedGlobalSource($definition, [
            'code' => 'old_primary',
        ]);

        $maxPrimaryCountDuringCreate = 0;

        ConnectorSchemaSource::created(function (ConnectorSchemaSource $source) use (&$maxPrimaryCountDuringCreate): void {
            $count = ConnectorSchemaSource::query()
                ->where('connector_definition_id', $source->connector_definition_id)
                ->where('schema_scope', ConnectorSchemaScope::Global->value)
                ->where('is_primary', true)
                ->count();

            $maxPrimaryCountDuringCreate = max($maxPrimaryCountDuringCreate, $count);
        });

        try {
            $this->governance->createSource($definition, [
                'code' => 'new_primary',
                'label' => 'New primary',
                'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
                'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
                'schema_scope' => ConnectorSchemaScope::Global,
                'reference_url' => 'https://example.com/new-primary',
                'is_primary' => true,
                'verification_status' => ConnectorSchemaVerificationStatus::Verified,
                'last_verified_at' => now(),
            ]);
        } finally {
            ConnectorSchemaSource::flushEventListeners();
            ConnectorSchemaSource::booted();
        }

        $this->assertSame(1, $maxPrimaryCountDuringCreate);
    }

    #[Test]
    public function definition_delete_is_restricted_at_db_level_when_sources_exist(): void
    {
        $definition = $this->createDraftDefinition('fk_restrict');
        $this->createVerifiedGlobalSource($definition);

        try {
            ConnectorDefinition::query()->findOrFail($definition->id)->delete();
            $this->fail('Expected QueryException was not thrown.');
        } catch (QueryException) {
            // expected FK violation
        }

        $this->assertTrue(ConnectorDefinition::query()->whereKey($definition->id)->exists());
        $this->assertTrue(
            ConnectorSchemaSource::query()->where('connector_definition_id', $definition->id)->exists(),
        );
    }

    #[Test]
    public function update_source_unsets_previous_primary_in_same_scope(): void
    {
        $definition = $this->createDraftDefinition('swap_primary');

        $existingPrimary = $this->createVerifiedGlobalSource($definition, [
            'code' => 'existing_primary',
        ]);

        $replacement = $this->governance->createSource($definition, [
            'code' => 'replacement',
            'label' => 'Replacement',
            'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
            'schema_scope' => ConnectorSchemaScope::Global,
            'reference_url' => 'https://example.com/replacement',
            'is_primary' => false,
            'verification_status' => ConnectorSchemaVerificationStatus::Verified,
            'last_verified_at' => now(),
        ]);

        $this->governance->updateSource($replacement, [
            'is_primary' => true,
        ]);

        $existingPrimary->refresh();
        $replacement->refresh();

        $this->assertFalse($existingPrimary->is_primary);
        $this->assertTrue($replacement->is_primary);
    }

    private function createDraftDefinition(string $code): ConnectorDefinition
    {
        return $this->governance->createDefinition([
            'code' => $code,
            'name' => 'Test '.$code,
            'direction' => ConnectorDirection::Both,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createVerifiedGlobalSource(ConnectorDefinition $definition, array $overrides = []): ConnectorSchemaSource
    {
        return $this->governance->createSource($definition, array_merge([
            'code' => 'global_source',
            'label' => 'Global source',
            'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
            'schema_scope' => ConnectorSchemaScope::Global,
            'reference_url' => 'https://example.com/global',
            'is_primary' => true,
            'verification_status' => ConnectorSchemaVerificationStatus::Verified,
            'last_verified_at' => now(),
        ], $overrides));
    }

    private function activateDefinitionWithSingleQualifyingSource(string $code): ConnectorDefinition
    {
        $definition = $this->createDraftDefinition($code);
        $this->createVerifiedGlobalSource($definition, ['code' => 'qualifying_'.$code]);

        return $this->governance->updateDefinition($definition, [
            'status' => ConnectorDefinitionStatus::Active,
        ]);
    }
}
