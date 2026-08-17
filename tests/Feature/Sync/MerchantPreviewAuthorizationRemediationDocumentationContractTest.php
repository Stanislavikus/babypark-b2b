<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MerchantPreviewAuthorizationRemediationDocumentationContractTest extends TestCase
{
    #[Test]
    public function domain_model_documents_manage_sync_configurations_as_normative_ninth_permission_pending_stage_2a(): void
    {
        $section = $this->stage20ContractSection();

        $this->assertStringContainsString('`manage_sync_configurations`', $section);
        $this->assertStringContainsString('Normative ninth workspace permission', $section);
        $this->assertStringContainsString('implementation pending Stage 2A', $section);
        $this->assertStringContainsString('**eight** permissions', $section);
    }

    #[Test]
    public function domain_model_documents_run_sync_preview_does_not_authorize_sync_configuration_mutation(): void
    {
        $section = $this->stage20ContractSection();

        $this->assertStringContainsString('No hidden configuration mutation from Preview', $section);
        $this->assertStringContainsString('authorized only by `run_sync_preview` must **not**', $section);
        $this->assertStringContainsString('create a `SyncConfiguration`', $section);
        $this->assertStringContainsString('call an `ensure*()` helper', $section);
    }

    #[Test]
    public function domain_model_documents_manage_sync_configurations_independence_from_connector_mapping_and_preview(): void
    {
        $section = $this->stage20ContractSection();

        $this->assertStringContainsString('Permission independence matrix (frozen)', $section);
        $this->assertStringContainsString('`manage_sync_configurations`', $section);
        $this->assertStringContainsString('Does **not** grant', $section);
        $this->assertStringContainsString('Connector credentials/settings mutation', $section);
        $this->assertStringContainsString('FieldMapping/FieldOptionMapping mutation', $section);
        $this->assertStringContainsString('Preview execution', $section);
        $this->assertStringContainsString('`run_sync_preview`', $section);
        $this->assertStringContainsString('SyncConfiguration mutation', $section);
    }

    #[Test]
    public function domain_model_documents_non_mutating_existence_check_as_required_stage_2a_scope(): void
    {
        $section = $this->stage20ContractSection();

        $this->assertStringContainsString('Non-mutating existence check (Stage 2A required scope)', $section);
        $this->assertStringContainsString('SyncPreviewConfigurationReadinessPort::isReady', $section);
        $this->assertStringContainsString('ensureProductsExportConfiguration()', $section);
        $this->assertStringContainsString('genuinely non-mutating existence/lookup method', $section);
        $this->assertStringContainsString('without** calling either', $section);
    }

    #[Test]
    public function domain_model_documents_three_layer_attribute_set_trace_and_pre_admission_distinction(): void
    {
        $section = $this->stage20ContractSection();

        $this->assertStringContainsString('Three-layer Adobe attribute-set failure trace (frozen)', $section);
        $this->assertStringContainsString('Write-time validation', $section);
        $this->assertStringContainsString('Admission/readiness validation', $section);
        $this->assertStringContainsString('Completed-Preview findings', $section);
        $this->assertStringContainsString('AttributeSetUnconfigured', $section);
        $this->assertStringContainsString('AttributeSetInvalid', $section);
        $this->assertStringContainsString('Pre-Preview setup vs completed Preview findings', $section);
        $this->assertStringContainsString('Потрібно завершити налаштування перед', $section);
        $this->assertStringContainsString('перевіркою', $section);
        $this->assertStringContainsString('fake Product-level `SyncRunItem` findings', $section);
    }

    #[Test]
    public function domain_model_documents_configuration_revision_does_not_prove_product_data_freshness(): void
    {
        $section = $this->stage20ContractSection();

        $this->assertStringContainsString('Configuration drift vs Product-data freshness', $section);
        $this->assertStringContainsString('snapshot or prove freshness of Product/Variant values', $section);
        $this->assertStringContainsString('explicit new Preview', $section);
    }

    #[Test]
    public function domain_model_documents_historical_cause_and_current_remediation_split(): void
    {
        $section = $this->stage20ContractSection();

        $this->assertStringContainsString('Historical finding vs current remediation (frozen)', $section);
        $this->assertStringContainsString('SyncRunItem.findings + run configuration_snapshot', $section);
        $this->assertStringContainsString('current authorization + current destination existence', $section);
        $this->assertStringContainsString('Historical findings are never rewritten', $section);
    }

    #[Test]
    public function domain_model_documents_remediation_presentation_without_sync_issue(): void
    {
        $section = $this->stage20ContractSection();

        $this->assertStringContainsString('Remediation presentation contract (presentation-only; no persistence)', $section);
        $this->assertStringContainsString('Do **not** create `SyncIssue`', $section);
        $this->assertStringContainsString('NO_EDIT_SURFACE', $section);
        $this->assertStringContainsString('CONNECTOR_SETUP', $section);
        $this->assertStringContainsString('CURRENT_CONFIGURATION_CHANGED', $section);
    }

    #[Test]
    public function domain_model_documents_stage_2a_and_2b_sequencing(): void
    {
        $domainModel = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('**Stage 2-0 — Merchant Preview Authorization & Remediation Contract**', $domainModel);
        $this->assertStringContainsString('**Stage 2A — Merchant Preview Core + Connector Setup**', $domainModel);
        $this->assertStringContainsString('**Stage 2B — Minimal Option Mapping Remediation**', $domainModel);
    }

    #[Test]
    public function domain_model_does_not_claim_adobe_warning_zero_is_permanent_invariant(): void
    {
        $section = $this->stage20ContractSection();

        $this->assertStringContainsString('Preview outcomes remain three-state', $section);
        $this->assertStringContainsString('frozen invariant that Adobe Warning', $section);
        $this->assertStringContainsString('must always equal 0', $section);
        $this->assertStringContainsString('correctly render zero warnings today', $section);
    }

    #[Test]
    public function ux_contract_documents_stage_2_0_merchant_preview_authorization_and_remediation(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('## 16. Merchant Preview authorization and remediation (Resolved — Stage 2-0)', $content);
        $this->assertStringContainsString('`manage_sync_configurations`', $content);
        $this->assertStringContainsString('runtime pending Stage', $content);
        $this->assertStringContainsString('No fake Fix', $content);
        $this->assertStringContainsString('create `SyncIssue`', $content);
        $this->assertStringContainsString('`attribute_set_id` as merchant', $content);
    }

    #[Test]
    public function ui_design_system_documents_merchant_preview_interaction_rules(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('### Merchant Preview interaction rules (Resolved — Stage 2-0)', $content);
        $this->assertStringContainsString('Needs attention first', $content);
        $this->assertStringContainsString('Honest action state', $content);
        $this->assertStringContainsString('NO_EDIT_SURFACE', $content);
        $this->assertStringContainsString('Zero warnings may be correct today', $content);
    }

    #[Test]
    public function implementation_gaps_records_stage_2_0_2a_2b_and_manage_sync_configurations_runtime_pending(): void
    {
        $gaps = $this->gap006Section();

        $this->assertStringContainsString('**Stage 2-0 — Merchant Preview Authorization & Remediation Contract**', $gaps);
        $this->assertStringContainsString('**Stage 2A — Merchant Preview Core + Connector Setup**', $gaps);
        $this->assertStringContainsString('**Stage 2B — Option Mapping Remediation**', $gaps);
        $this->assertStringContainsString('runtime pending Stage 2A', $gaps);
        $this->assertStringContainsString('runtime catalogue remains **eight**', $gaps);
        $this->assertStringNotContainsString('| **Stage 2 — Merchant Preview** |', $gaps);
    }

    #[Test]
    public function atlas_documents_manage_sync_configurations_and_existence_lookup_as_not_implemented(): void
    {
        $atlas = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('`manage_sync_configurations` permission | RESOLVED — NOT IMPLEMENTED', $atlas);
        $this->assertStringContainsString('SyncConfiguration non-mutating existence lookup', $atlas);
        $this->assertStringContainsString('RESOLVED — NOT IMPLEMENTED', $atlas);
        $this->assertStringContainsString('must not call `ensure*()` helpers', $atlas);
    }

    /**
     * @return non-empty-string
     */
    private function stage20ContractSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/#### Merchant Preview Authorization & Remediation Contract\n\[Resolved — Stage 2-0\]\n\n(.*?)'
            .'(?=\n### Canonical mapping registry role)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Stage 2-0 Merchant Preview Authorization contract in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function gap006Section(): string
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        if (! preg_match('/## GAP-006 —.*?(?=\n## GAP-007 —)/s', $content, $matches)) {
            $this->fail('Could not locate GAP-006 section in IMPLEMENTATION_GAPS.md');
        }

        return $matches[0];
    }
}
