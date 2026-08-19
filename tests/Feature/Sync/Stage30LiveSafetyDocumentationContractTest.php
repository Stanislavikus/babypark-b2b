<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Stage30LiveSafetyDocumentationContractTest extends TestCase
{
    #[Test]
    public function domain_model_documents_stage_3_0_live_safety_contract(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '### Live Safety, Identity & First-Live Contract',
            $content,
        );
        $this->assertStringContainsString('[Resolved — Stage 3-0]', $content);
    }

    #[Test]
    public function stage_3_0_is_docs_only_and_does_not_authorize_runtime(): void
    {
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString('**Docs-only in Stage 3-0.**', $section);
        $this->assertStringContainsString('No runtime implementation', $section);
        $this->assertStringContainsString('Adobe write', $section);
        $this->assertStringContainsString('Production Live', $section);
        $this->assertStringContainsString('**NOT IMPLEMENTED**', $section);
    }

    #[Test]
    public function current_baseline_truth_documents_stage_progression(): void
    {
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString('Stage 1 — Preview Engine', $section);
        $this->assertStringContainsString('**Done**', $section);
        $this->assertStringContainsString('Stage 2A — Merchant Preview', $section);
        $this->assertStringContainsString('Stage 2B — Option Mapping Remediation', $section);
        $this->assertStringContainsString('Stage 3-0 — Live Safety, Identity & First-Live Contract', $section);
        $this->assertStringContainsString('**Done (docs contract)**', $section);
        $this->assertStringContainsString('Stage 3A — Live Safety Foundation', $section);
        $this->assertStringContainsString('Stage 3B–3E — Live implementation slices', $section);
        $this->assertStringContainsString('**Pending**', $section);
    }

    #[Test]
    public function run_sync_live_permission_is_implemented_in_stage_3a(): void
    {
        $e8 = $this->e8Section();

        $this->assertStringContainsString('**tenth** atomic workspace permission', $e8);
        $this->assertStringContainsString('`run_sync_live`', $e8);

        $atlas = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));
        $this->assertStringContainsString('| `run_sync_live` permission | IMPLEMENTED (Stage 3A) |', $atlas);
    }

    #[Test]
    public function preview_permission_does_not_imply_live(): void
    {
        $e8 = $this->e8Section();

        $this->assertStringContainsString('Preview permission != Live permission', $e8);
        $this->assertStringContainsString('`run_sync_preview`', $e8);
        $this->assertStringContainsString('`manage_sync_configurations`', $e8);
        $this->assertStringContainsString('`view_sync_mappings` / `manage_sync_mappings`', $e8);
        $this->assertStringContainsString('**no** automatic grant', $e8);
    }

    #[Test]
    public function one_active_run_remains_mode_agnostic(): void
    {
        $e10 = $this->e10Section();

        $this->assertStringContainsString('mode-agnostic', $e10);
        $this->assertStringContainsString('Preview+Preview', $e10);
        $this->assertStringContainsString('Preview+Live', $e10);
        $this->assertStringContainsString('Live+Preview', $e10);
        $this->assertStringContainsString('Live+Live', $e10);
    }

    #[Test]
    public function stale_recovery_must_prevent_overlapping_consequential_writers(): void
    {
        $e10 = $this->e10Section();
        $e11 = $this->e11Section();
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString('execution-lease', $e11);
        $this->assertStringContainsString('overlapping consequential writers', $e10);
        $this->assertStringContainsString('must not start a **new** consequential external request', $e11);
        $this->assertStringContainsString('stale active-run recovery exists (Stage 3A)', $section);
    }

    #[Test]
    public function live_job_requires_tries_one(): void
    {
        $e10 = $this->e10Section();
        $e11 = $this->e11Section();

        $this->assertStringContainsString('`tries = 1`', $e10);
        $this->assertStringContainsString('independent of worker `--tries`', $e11);
    }

    #[Test]
    public function preview_connector_plan_is_not_a_live_write_script(): void
    {
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString('`connectorPlan`', $section);
        $this->assertStringContainsString('Live HTTP command plan', $section);
        $this->assertStringContainsString('`AdobeProductExportPreviewPlan` is **not** a Live write script', $section);
        $this->assertStringContainsString('Do not implement one shared mutating `execute(..., dryRun=true)` path', $section);
    }

    #[Test]
    public function external_record_link_is_account_scoped_not_config_scoped(): void
    {
        $e9 = $this->e9Section();

        $this->assertStringContainsString('ConnectorAccount-scoped', $e9);
        $this->assertStringContainsString('**not** SyncConfiguration-scoped', $e9);
        $this->assertStringContainsString('absent until Stage 3A', $e9);
    }

    #[Test]
    public function external_record_link_uses_typed_product_and_variant_identity(): void
    {
        $e9 = $this->e9Section();

        $this->assertStringContainsString('`Product`', $e9);
        $this->assertStringContainsString('`ProductVariant`', $e9);
        $this->assertStringContainsString('`product_id`', $e9);
        $this->assertStringContainsString('`product_variant_id`', $e9);
        $this->assertStringContainsString('exactly one of `product_id` / `product_variant_id` is non-null', $e9);
    }

    #[Test]
    public function external_record_link_allows_fan_out_but_prevents_exact_duplicate_association(): void
    {
        $e9 = $this->e9Section();

        $this->assertStringContainsString('Fan-out remains allowed', $e9);
        $this->assertStringContainsString('UNIQUE(workspace_id, connector_account_id, product_id, external_identifier)', $e9);
        $this->assertStringContainsString('UNIQUE(workspace_id, connector_account_id, product_variant_id, external_identifier)', $e9);
        $this->assertStringContainsString('Do **not** freeze `UNIQUE(connector_account_id,', $e9);
    }

    #[Test]
    public function adobe_configurable_parent_sku_is_connector_owned_generated_identity(): void
    {
        $identity = $this->adobeIdentityNotesSection();

        $this->assertStringContainsString('connector-owned generated external identity', $identity);
        $this->assertStringContainsString('Do **not** silently use physical `products.sku`', $identity);
        $this->assertStringContainsString('`ProductVariant` link', $identity);
    }

    #[Test]
    public function live_requires_current_revision_preview_and_fresh_replan(): void
    {
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString('current** `configuration_revision`', $section);
        $this->assertStringContainsString('rebuild Product execution aggregates from fresh', $section);
        $this->assertStringContainsString('evaluate the shared Adobe semantic planning truth again', $section);
        $this->assertStringContainsString('historical Preview `connectorPlan` must **not** be executed', $section);
    }

    #[Test]
    public function no_product_revision_and_no_preview_ttl(): void
    {
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString('Do **not** require arbitrary Preview-age TTL', $section);
        $this->assertStringContainsString('Product-wide revision', $section);
        $this->assertStringContainsString('No arbitrary "Preview older than N minutes" rule', $section);
    }

    #[Test]
    public function blocked_products_do_not_globally_forbid_live(): void
    {
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString('Blocked Products do not globally prevent Live', $section);
        $this->assertStringContainsString('`NOT_APPLIED` with zero write', $section);
    }

    #[Test]
    public function live_outcome_vocabulary_is_distinct_from_preview(): void
    {
        $outcomes = $this->e101Section();

        $this->assertStringContainsString('`ready` / `warning` / `blocked`', $outcomes);
        $this->assertStringContainsString('`SYNCHRONIZED`', $outcomes);
        $this->assertStringContainsString('`NOT_APPLIED`', $outcomes);
        $this->assertStringContainsString('`PARTIAL`', $outcomes);
        $this->assertStringContainsString('`AMBIGUOUS`', $outcomes);
        $this->assertStringContainsString('Do **not** create item-level `FAILED`', $outcomes);
    }

    #[Test]
    public function e13_preserves_disable_not_delete_semantics(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('#### E13. Deactivation / removal semantics', $content);
        $this->assertStringContainsString('Do not delete the external resource', $content);
        $this->assertStringContainsString('Do not delete the Adobe child in V1', $content);
    }

    #[Test]
    public function e14_remains_part_of_adobe_v1(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('#### E14. Rich media scope for Magento V1', $content);
        $this->assertStringContainsString('export primary image and additional', $content);
        $this->assertStringContainsString('required E14 image behavior', $this->stage30ContractSection());
    }

    #[Test]
    public function selective_retry_remains_deferred(): void
    {
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString('Selective retry is out', $section);
        $this->assertStringContainsString('selection.mode = all_products', $section);
        $this->assertStringContainsString('"Retry failed only"', $section);
    }

    #[Test]
    public function live_support_remains_false_through_internal_slices(): void
    {
        $section = $this->stage30ContractSection();
        $stages = $this->coherentStagesSection();

        $this->assertStringContainsString('Products / Export / Preview = **true**', $section);
        $this->assertStringContainsString('Products / Export / Live = **false**', $section);
        $this->assertStringContainsString('Keep Live **false** through internal implementation slices 3A–3D', $section);
        $this->assertStringContainsString('remains **false**', $stages);
    }

    #[Test]
    public function real_adobe_proof_required_before_support_flip(): void
    {
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString('Real Adobe validation gate', $section);
        $this->assertStringContainsString('disposable Adobe smoke', $section);
        $this->assertStringContainsString('Stage 3E', $section);
        $this->assertStringContainsString('explicit human authorization', $section);
    }

    #[Test]
    public function stage_3a_through_3e_sequence_is_documented(): void
    {
        $stages = $this->coherentStagesSection();
        $gaps = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('**3A — Live Safety Foundation**', $stages);
        $this->assertStringContainsString('**3B — Adobe Simple Live**', $stages);
        $this->assertStringContainsString('**3C — Adobe Configurable Live**', $stages);
        $this->assertStringContainsString('**3D — Adobe Media + Merchant First Live**', $stages);
        $this->assertStringContainsString('**3E — Real Adobe Validation + Truth Flip**', $stages);

        $this->assertStringContainsString('**Stage 3-0 — Live Safety, Identity & First-Live Contract**', $gaps);
        $this->assertStringContainsString('**Stage 3A — Live Safety Foundation**', $gaps);
        $this->assertStringContainsString('**Stage 3B–3E — Live Engine implementation slices**', $gaps);
    }

    #[Test]
    public function explicit_non_goals_exclude_scheduling_history_issues_and_bulk_transport(): void
    {
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString('Explicit non-goals (Stage 3)', $section);
        $this->assertStringContainsString('Scheduling', $section);
        $this->assertStringContainsString('Sync History', $section);
        $this->assertStringContainsString('SyncIssue', $section);
        $this->assertStringContainsString('Adobe bulk/async APIs', $section);
        $this->assertStringContainsString('selective retry', $section);
    }

    #[Test]
    public function implementation_gaps_documents_stage_3_0_done(): void
    {
        $gaps = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('Stage 3-0 docs contract **Done**', $gaps);
    }

    #[Test]
    public function historical_4c2a_deferred_live_wording_is_superseded_by_stage_3_0(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/#### Preview-first Sync Execution Foundation Contract\n\[Resolved — Task 4C-2a\]\n\n(.*?)(?=\n### Magento Product Export V1 Execution Contract)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Preview-first Sync Execution Foundation Contract section');
        }

        $previewSection = $matches[1];

        $this->assertStringContainsString('**Historical 4C-2a state:**', $previewSection);
        $this->assertStringContainsString('Preview-vs-Live coexistence was intentionally deferred', $previewSection);
        $this->assertStringContainsString('at that stage', $previewSection);
        $this->assertStringContainsString('**Current truth (Stage 3-0):**', $previewSection);
        $this->assertStringContainsString('now fulfilled by **Stage 3-0**', $previewSection);
        $this->assertStringContainsString(
            'historical 4C-2a sequencing — prerequisite now fulfilled by Stage 3-0',
            $previewSection,
        );
        $this->assertStringNotContainsString(
            'Preview-vs-Live coexistence remains deferred',
            $previewSection,
        );
    }

    #[Test]
    public function run_sync_live_alone_is_insufficient_for_merchant_live_exposure(): void
    {
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString('`run_sync_live` means **authority**', $section);
        $this->assertStringContainsString('does **not** mean the connector/runtime', $section);
        $this->assertStringContainsString('currently **supports** Live', $section);
        $this->assertStringContainsString('Merchant consequential Live admission/exposure requires **all** relevant gates', $section);
    }

    #[Test]
    public function completed_preview_alone_is_insufficient_for_merchant_live_exposure(): void
    {
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString('**trust/readiness prerequisite**', $section);
        $this->assertStringContainsString('make unsupported Live', $section);
        $this->assertStringContainsString('executable', $section);
    }

    #[Test]
    public function connector_sync_operation_support_live_is_mandatory_availability_gate(): void
    {
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString(
            '`ConnectorSyncOperationSupport(Products, Export, Live) === true`',
            $section,
        );
        $this->assertStringContainsString('No Stage 3D code may bypass', $section);
        $this->assertStringContainsString('`ConnectorSyncOperationSupport`', $section);
    }

    #[Test]
    public function stage_3d_cannot_bypass_live_false_support_truth(): void
    {
        $stages = $this->coherentStagesSection();
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString('non-actionable', $stages);
        $this->assertStringContainsString('must not bypass `ConnectorSyncOperationSupport`', $stages);
        $this->assertStringContainsString('Keep Live **false** through internal implementation slices 3A–3D', $section);
    }

    #[Test]
    public function merchant_consequential_exposure_waits_for_stage_3e_support_flip(): void
    {
        $section = $this->stage30ContractSection();

        $this->assertStringContainsString('Merchant actionable exposure happens only after', $section);
        $this->assertStringContainsString('successful Stage 3E real-Adobe validation', $section);
    }

    #[Test]
    public function adobe_products_export_live_remains_false_in_current_runtime(): void
    {
        $section = $this->stage30ContractSection();
        $atlas = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('Products / Export / Live = **false**', $section);
        $this->assertStringContainsString('| Adobe Products/Export/Live support truth | CONFIRMED ABSENT (public) |', $atlas);
    }

    #[Test]
    public function ux_contract_documents_merchant_first_live_section(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('## 17. Merchant First-Live UX (Resolved — Stage 3-0)', $content);
        $this->assertStringContainsString('ManageAdobeProductsExportPreview', $content);
        $this->assertStringContainsString('Передати товари в Adobe Commerce?', $content);
        $this->assertStringContainsString('ConnectorSyncOperationSupport(Products, Export, Live) === true', $content);
        $this->assertStringContainsString('Stage 3D must not bypass `ConnectorSyncOperationSupport`', $content);
    }

    #[Test]
    public function ui_design_system_documents_merchant_first_live_rules(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('### Merchant First-Live interaction rules (Resolved — Stage 3-0)', $content);
        $this->assertStringContainsString('no "retry failed only" action in Stage 3 V1', $content);
        $this->assertStringContainsString('ConnectorSyncOperationSupport(Products, Export, Live) === true', $content);
        $this->assertStringContainsString('Stage 3D may implement Live UI/read model while support is', $content);
        $this->assertStringContainsString('**false**, but the action must remain non-actionable', $content);
    }

    /**
     * @return non-empty-string
     */
    private function stage30ContractSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/### Live Safety, Identity & First-Live Contract\n\[Resolved — Stage 3-0\]\n\n(.*?)(?=\n### Canonical mapping registry role)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Stage 3-0 contract section in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function coherentStagesSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/#### Coherent implementation stages\n\n(.*?)(?=\n#### Merchant Preview Authorization)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate coherent implementation stages section');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function e8Section(): string
    {
        return $this->extractSubsection('#### E8. Live authority', '#### E9.');
    }

    /**
     * @return non-empty-string
     */
    private function e9Section(): string
    {
        return $this->extractSubsection('#### E9. ExternalRecordLink structural contract', '#### Adobe Magento V1 identity notes');
    }

    /**
     * @return non-empty-string
     */
    private function adobeIdentityNotesSection(): string
    {
        return $this->extractSubsection('#### Adobe Magento V1 identity notes', '#### E10.');
    }

    /**
     * @return non-empty-string
     */
    private function e10Section(): string
    {
        return $this->extractSubsection('#### E10. Live safety — hard invariants NOW', '#### E10.1');
    }

    /**
     * @return non-empty-string
     */
    private function e101Section(): string
    {
        return $this->extractSubsection('#### E10.1 Live Product outcomes (frozen — Stage 3-0)', '#### E11.');
    }

    /**
     * @return non-empty-string
     */
    private function e11Section(): string
    {
        return $this->extractSubsection('#### E11. Live safety — mechanics NOT over-frozen', '#### E12.');
    }

    /**
     * @return non-empty-string
     */
    private function extractSubsection(string $start, string $end): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));
        $pattern = '/'.preg_quote($start, '/').'\n\n(.*?)(?=\n'.preg_quote($end, '/').')/s';

        if (! preg_match($pattern, $content, $matches)) {
            $this->fail("Could not locate subsection starting with {$start}");
        }

        return $matches[1];
    }
}
