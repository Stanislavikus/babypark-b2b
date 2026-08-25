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

    public function test_writer_is_absent_today(): void
    {
        $this->assertStringContainsString(
            'absent today',
            $this->domainModelContent,
        );
        $this->assertStringContainsString(
            'When implemented, this writer MUST be the governed boundary',
            $this->domainModelContent,
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

    public function test_import_runtime_remains_absent_in_atlas(): void
    {
        $atlasContent = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));
        $this->assertStringContainsString(
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

    private function extractGap028Section(string $content): string
    {
        if (preg_match('/## GAP-028 —[^\n]*\n(.*?)(?=\n## |\z)/s', $content, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
