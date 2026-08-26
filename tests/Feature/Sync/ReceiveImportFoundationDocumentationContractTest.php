<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ReceiveImportFoundationDocumentationContractTest extends TestCase
{
    private string $domainModelContent;

    private string $gapsContent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domainModelContent = File::get(base_path('docs/03-DOMAIN_MODEL.md'));
        $this->gapsContent = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));
    }

    public function test_field_mapping_remains_direction_neutral(): void
    {
        $this->assertStringContainsString(
            'FieldMapping is Direction-Neutral',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            '`direction`, `authority`, `import_enabled`, `export_enabled`, `master_system`, or `last_writer`',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'does **not** imply that the mapped field must execute in every enabled semantic operation',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'Execution eligibility may differ by semantic operation, connector/runtime capability, domain ownership policy, operation-specific planner/transformation, and future verified per-operation configuration',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'Independent per-operation mapping/configuration remains deferred until a verified product requirement exists',
            $this->domainModelContent,
        );
    }

    public function test_receive_contract_scope_is_connector_backed_sync_domain_only(): void
    {
        $this->assertStringContainsString(
            'This contract governs connector-backed Receive through the Sync Domain (`ConnectorAccount` + `SyncConfiguration`) path',
            $this->domainModelContent,
        );
    }

    public function test_smart_import_and_csv_are_not_forced_through_connector_receive_semantics(): void
    {
        $this->assertStringContainsString(
            'It does **not** redefine the separate Smart Import / spreadsheet / CSV onboarding flow',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'File/snapshot imports may reuse shared Product/Variant domain writers and Field Foundation invariants',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'do **not** automatically inherit `ExternalRecordLink`, ENTITY TRUST, live remote reread, or Magento entity-bound transport requirements',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'their own source identity, provenance, and staleness semantics remain governed by their own import architecture',
            $this->domainModelContent,
        );
    }

    public function test_no_mandatory_per_field_authority_introduced(): void
    {
        $this->assertStringContainsString(
            'Manual Receive requires **no** persistent field-level ownership',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'does **not** reopen that open decision',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'Field/data-domain write ownership',
            $this->domainModelContent,
        );
    }

    public function test_baseline_required_before_unattended_conflict_claims(): void
    {
        $this->assertStringContainsString(
            'persisted synchronization baseline',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'before** the platform may make unattended conflict claims',
            $this->domainModelContent,
        );
    }

    public function test_automation_ownership_remains_open(): void
    {
        $this->assertStringContainsString(
            'existing open Product/domain decision',
            $this->domainModelContent,
        );
    }

    public function test_gap_028_writer_is_closed_for_supported_generic_dynamic_types(): void
    {
        $this->assertStringContainsString(
            'GAP-028 is implemented today as the current governed boundary for ordinary dynamic `Text`, `LongText`, `Number`, `Decimal`, `Boolean`, `Date`, single-value `Select`, `MultiSelect`, and `Url` fields',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'This writer MUST validate/enforce at minimum',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'Current generic GAP-028 scope: `Text`, `LongText`, `Number`, `Decimal`, `Boolean`, `Date`, single-value `Select`, `MultiSelect`, `Url`.',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'GAP-028 is **Closed**.',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            '**Status:** Closed.',
            $this->gapsContent,
        );
        $this->assertStringContainsString(
            '`Money` remains owned by the Pricing domain, `Image` remains owned by the Media domain, and `Computed` remains derived / non-writable; they are outside the generic GAP-028 writer.',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            '`Money`, `Image`, and `Computed` are **not** part of the generic GAP-028 follow-up typed extension',
            $this->gapsContent,
        );
        $this->assertStringContainsString(
            'GAP-029 now covers the separate column-backed route.',
            File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md')),
        );
    }

    public function test_writer_validates_workspace_scope(): void
    {
        $this->assertStringContainsString(
            'explicit `Workspace` scope',
            $this->domainModelContent,
        );
    }

    public function test_writer_validates_active_definition_and_binding(): void
    {
        $this->assertStringContainsString(
            'active `FieldDefinition`',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'active `FieldBinding`',
            $this->domainModelContent,
        );
    }

    public function test_writer_enforces_product_vs_variant_routing(): void
    {
        $this->assertStringContainsString(
            'correct `Product` vs `ProductVariant` object type',
            $this->domainModelContent,
        );
    }

    public function test_writer_enforces_datatype(): void
    {
        $this->assertStringContainsString(
            'declared data type',
            $this->domainModelContent,
        );
    }

    public function test_writer_enforces_option_validity(): void
    {
        $this->assertStringContainsString(
            'option validity/resolution where applicable',
            $this->domainModelContent,
        );
    }

    public function test_writer_enforces_localization_storage_invariant(): void
    {
        $this->assertStringContainsString(
            'prohibition of illegal flat overwrites of `is_localizable = true` structured values',
            $this->domainModelContent,
        );
    }

    public function test_writer_excludes_pricing_availability_media_relations(): void
    {
        $this->assertStringContainsString(
            '**Pricing**',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            '**Availability / Inventory**',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            '**Media**',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            '**Relations / categories**',
            $this->domainModelContent,
        );
    }

    public function test_proposal_is_not_syncrun_history_or_authority(): void
    {
        $this->assertStringContainsString(
            'Receive Proposal/Diff is Not SyncRun Preview',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'not execution history, authorization, identity, or ENTITY TRUST',
            $this->domainModelContent,
        );
    }

    public function test_apply_requires_fresh_authorization(): void
    {
        $this->assertStringContainsString(
            'Apply-Time Revalidation is Mandatory',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'actor authorization',
            $this->domainModelContent,
        );
    }

    public function test_apply_requires_configuration_revision(): void
    {
        $this->assertStringContainsString(
            '`SyncConfiguration.configuration_revision`',
            $this->domainModelContent,
        );
    }

    public function test_apply_requires_trusted_erl_and_entity(): void
    {
        $this->assertStringContainsString(
            'existing trusted `ExternalRecordLink`',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'remote logical identity',
            $this->domainModelContent,
        );
    }

    public function test_apply_requires_local_state_recheck(): void
    {
        $this->assertStringContainsString(
            'participating local',
            $this->domainModelContent,
        );
    }

    public function test_apply_requires_remote_state_recheck(): void
    {
        $this->assertStringContainsString(
            'remote values have not changed',
            $this->domainModelContent,
        );
    }

    public function test_stale_proposal_invalidates_to_zero_mutation(): void
    {
        $this->assertStringContainsString(
            'If state has changed, the proposal is invalidated and requires a rebuild (zero mutation)',
            $this->domainModelContent,
        );
    }

    public function test_discovered_must_remain_in_snapshot_truth(): void
    {
        $this->assertStringContainsString(
            'remain represented in the authoritative schema/snapshot truth',
            $this->domainModelContent,
        );
    }

    public function test_discovered_must_not_be_silently_converted_or_discarded(): void
    {
        $this->assertStringContainsString(
            'MUST NOT be silently converted into supported mappings or discarded',
            $this->domainModelContent,
        );
    }

    public function test_merchant_mapping_ui_not_required_to_list_every_field(): void
    {
        $this->assertStringContainsString(
            'does **not** require the primary merchant mapping UI to list every discovered external field',
            $this->domainModelContent,
        );
    }

    public function test_stock_rest_transport_is_candidate_not_proven(): void
    {
        $this->assertStringContainsString(
            '**candidate** transport for entity-bound Receive, not a proven production assumption',
            $this->domainModelContent,
        );
    }

    public function test_real_target_proof_required(): void
    {
        $this->assertStringContainsString(
            'A source-code inference alone is insufficient',
            $this->domainModelContent,
        );
    }

    public function test_safe_sync_fallback_remains(): void
    {
        $this->assertStringContainsString(
            'existing first-party Safe Sync entity-bound read remains the fallback',
            $this->domainModelContent,
        );
    }

    public function test_transport_does_not_weaken_entity_trust(): void
    {
        $this->assertStringContainsString(
            'does **not** weaken ENTITY TRUST',
            $this->domainModelContent,
        );
    }

    public function test_stage_3e_send_no_link_mutation_prohibition_preserved(): void
    {
        $this->assertStringContainsString(
            'no-link mutation prohibitions',
            $this->domainModelContent,
        );
    }

    public function test_stage_3e_no_blind_retry_preserved(): void
    {
        $this->assertStringContainsString(
            'no blind retry',
            $this->domainModelContent,
        );
    }

    public function test_stage_3e_public_live_support_remains_false(): void
    {
        $this->assertStringContainsString(
            'current support=false truth',
            $this->domainModelContent,
        );
    }

    public function test_receive_runtime_truth_is_granular_in_atlas(): void
    {
        $atlasContent = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));
        $this->assertStringContainsString(
            'Receive proposal/planner foundation | IMPLEMENTED',
            $atlasContent,
        );
        $this->assertStringContainsString(
            'Receive connector read/orchestration | CONFIRMED ABSENT',
            $atlasContent,
        );
        $this->assertStringContainsString(
            'Receive Apply runtime | CONFIRMED ABSENT',
            $atlasContent,
        );
        $this->assertStringContainsString(
            'connector-backed Sync Domain Receive',
            $atlasContent,
        );
        $this->assertStringNotContainsString(
            'Receive / Import runtime | CONFIRMED ABSENT',
            $atlasContent,
        );
    }

    public function test_gap_028_is_field_value_writer_not_ownership(): void
    {
        $this->assertStringContainsString(
            'GAP-028',
            $this->gapsContent,
        );
        $this->assertStringContainsString(
            'Missing governed Product/Variant field-value writer',
            $this->gapsContent,
        );

        // Scope the negative assertion to the GAP-028 section only, since
        // legacy GAP entries elsewhere in the document legitimately use
        // the word "ownership".
        $gap028 = $this->extractGap028Section($this->gapsContent);
        $this->assertStringContainsString(
            'field-value writer',
            $gap028,
        );
        $this->assertStringNotContainsString(
            'ownership',
            $gap028,
            'GAP-028 must not be described as about ownership; it is about the missing governed field-value writer.',
        );
    }

    public function test_gap_029_records_implemented_column_backed_mutation_boundary(): void
    {
        $this->assertStringContainsString(
            'GAP-029',
            $this->gapsContent,
        );
        $this->assertStringContainsString(
            'Missing governed Product/Variant column-backed mutation boundary',
            $this->gapsContent,
        );
        $this->assertStringContainsString(
            'Do **not** migrate column-backed fields into dynamic storage merely to reuse GAP-028',
            $this->gapsContent,
        );
        $this->assertStringContainsString(
            'explicit allowlist plus Product/Variant domain invariants',
            $this->gapsContent,
        );
        $this->assertStringContainsString(
            '`sku` remains excluded from first Receive',
            $this->gapsContent,
        );
        $this->assertStringContainsString(
            'First explicit allowlist = Product `name` + Product `description` only.',
            $this->gapsContent,
        );
        $this->assertStringContainsString(
            '**Status:** Closed.',
            $this->gapsContent,
        );
    }

    public function test_sync_configuration_identity_excludes_enabled_operations(): void
    {
        $this->assertStringContainsString(
            '`SyncConfiguration` identity is exactly:',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            '+ data_domain',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            '+ external_context',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'Enabled semantic operations (`import`, `export`, or both) are **configuration state**, NOT part of identity',
            $this->domainModelContent,
        );
    }

    public function test_no_separate_hidden_configurations_by_direction(): void
    {
        $this->assertStringContainsString(
            'Do **not** create separate hidden Import and Export configurations',
            $this->domainModelContent,
        );
    }

    public function test_dynamic_and_column_backed_routes_are_distinct(): void
    {
        $this->assertStringContainsString(
            'two **distinct** mutation routes',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            '`storage_type = dynamic`',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            '`storage_type = column`',
            $this->domainModelContent,
        );
    }

    public function test_column_backed_must_not_use_dynamic_writer(): void
    {
        $this->assertStringContainsString(
            'Column-backed values **MUST NOT** go through the generic dynamic field-value writer',
            $this->domainModelContent,
        );
    }

    public function test_storage_path_alone_does_not_grant_write(): void
    {
        $this->assertStringContainsString(
            'Storage path alone does not grant write capability',
            $this->domainModelContent,
        );
    }

    public function test_column_backed_requires_explicit_allowlist_and_product_variant_domain_mutation(): void
    {
        $this->assertStringContainsString(
            'explicit Receive allowlist',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'appropriate Product/Variant domain mutation boundary',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'GovernedProductVariantColumnMutationService.php',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'Every column-backed field must be **explicitly admitted**',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'First explicit allowlist: Product `name` and Product `description` only.',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'GAP-029 is **Closed**.',
            $this->domainModelContent,
        );
    }

    public function test_connector_code_must_not_use_broad_mass_assignment(): void
    {
        $this->assertStringContainsString(
            'broad `fill()`, mass assignment, or arbitrary `Model::update()` with remotely supplied values',
            $this->domainModelContent,
        );
    }

    public function test_sku_is_not_receive_writable_in_first_slice(): void
    {
        $this->assertStringContainsString(
            '`sku` is **NOT** Receive-writable in the first slice',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'SKU remains an identity/addressing precondition, not an incoming mutable field',
            $this->domainModelContent,
        );
    }

    public function test_pricing_availability_media_relations_still_excluded(): void
    {
        $this->assertStringContainsString(
            '**Pricing**',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            '**Availability / Inventory**',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            '**Media**',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            '**Relations / categories**',
            $this->domainModelContent,
        );
    }

    public function test_field_mapping_has_no_import_export_persistence_requirement(): void
    {
        $migration = File::get(base_path('database/migrations/2026_08_12_110000_field_mappings.php'));

        $this->assertStringNotContainsString('import_enabled', $migration);
        $this->assertStringNotContainsString('export_enabled', $migration);
    }

    public function test_atlas_records_implemented_column_backed_mutation_boundary(): void
    {
        $atlasContent = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString(
            'Governed Product/Variant column-backed mutation boundary | IMPLEMENTED (GAP-029 closed)',
            $atlasContent,
        );
        $this->assertStringContainsString(
            'GAP-029',
            $atlasContent,
        );
        $this->assertStringContainsString(
            'storage_type = Column` or `storage_path` alone is insufficient authority',
            $atlasContent,
        );
    }

    private function extractGap028Section(string $content): string
    {
        if (preg_match('/## GAP-028 —[^\n]*\n(.*?)(?=\n## |\z)/s', $content, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
