<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Documentation contract test for the Post-#168 / Post-D6 rebaseline.
 *
 * Locks in the normative content added in the
 * `docs/magento-v1-moduleless-stop-amend` campaign:
 *
 *   - Connector Benchmark-First rule in `05-AI_WORKING_AGREEMENT.md`.
 *   - Magento V1 Moduleless-by-default Stop-and-Amend in `03-DOMAIN_MODEL.md`.
 *   - Optional Safe Sync future comparative certification contract
 *     in `09-CONNECTOR_DELIVERY_PROTOCOL.md`.
 *   - Connector Account Overview verify-to-preview journey freeze
 *     in `CONNECTOR_INTEGRATION_UX_CONTRACT.md` (new section 19).
 *   - Intentional current-runtime vs new contract gap recorded in
 *     `08-CONNECTOR_SYNC_RUNTIME_ATLAS.md`.
 *   - Narrow distinction between current runtime owner and newly
 *     approved target architecture in
 *     `docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md`.
 *
 * The pre-existing Stage 3E entity-bound Safe Sync contract, the
 * Decision 6 PHP / Adobe certification matrix wording, and the
 * public `Adobe Products / Export / Live = FALSE` support table are
 * NOT weakened by this rebaseline. They are mechanically protected
 * by sibling tests in this directory (e.g.
 * `Stage3EEntityBoundSafeSyncDocumentationContractTest`,
 * `AdobeSafeSyncReadinessDocumentationContractTest`).
 */
class MagentoV1ModulelessRebaselineDocumentationContractTest extends TestCase
{
    #[Test]
    public function ai_working_agreement_documents_connector_benchmark_first_rule(): void
    {
        $content = File::get(base_path('docs/05-AI_WORKING_AGREEMENT.md'));

        $this->assertStringContainsString('## Connector Benchmark-First Rule', $content);

        // The 5 mandatory preconditions for proposing a custom mandatory
        // customer-side component (post-correction pass).
        $this->assertStringContainsString('Stock vendor integration / API research', $content);
        $this->assertStringContainsString('At least two mature benchmark patterns', $content);
        $this->assertStringContainsString('Exact missing stock capability / invariant identified', $content);
        $this->assertStringContainsString('Material Product Goal impact proven', $content);
        $this->assertStringContainsString('Explicit documentation-level Product / Architecture Decision', $content);
        $this->assertStringContainsString('AI reasoning alone is **never** sufficient', $content);

        $this->assertStringContainsString('triggers the Stop and Amend Rule', $content);

        // The corrected rule must NOT freeze "advanced / paid tier" as a
        // universal framing for legitimate installed components. Commercial
        // packaging / pricing / paid tiers are explicitly UNDECIDED and out
        // of scope of this rule.
        $new_section = $this->extractConnectorBenchmarkFirstRule($content);
        $this->assertStringNotContainsString('advanced / paid tier', $new_section);
        $this->assertStringNotContainsString('advanced or paid tier', $new_section);
        $this->assertStringContainsString('Commercial packaging, pricing, and paid tiers are explicitly', $new_section);
        $this->assertStringContainsString('UNDECIDED and out of scope of this rule', $new_section);
    }

    #[Test]
    public function domain_model_rebaseline_says_safe_sync_is_optional_enhanced_safety_capability_not_paid_tier(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $new_section = $this->extractMagentoV1ModulelessSection($content);
        $normalized_new_section = $this->normalizeDocWhitespace($new_section);

        // Safe Sync is reclassified as an optional "Enhanced Safety" candidate /
        // capability. The "advanced or paid tier" framing was removed in the
        // correction pass.
        $this->assertStringContainsString('Optional "Enhanced Safety" candidate', $new_section);
        $this->assertStringNotContainsString('advanced or paid tier', $new_section);
        $this->assertStringContainsString(
            'Commercial packaging, pricing, and paid tiers are explicitly',
            $normalized_new_section,
        );
        $this->assertStringContainsString(
            'UNDECIDED and out of scope of this rebaseline',
            $normalized_new_section,
        );
    }

    #[Test]
    public function delivery_protocol_says_safe_sync_is_optional_enhanced_safety_capability_not_paid_tier(): void
    {
        $content = File::get(base_path('docs/09-CONNECTOR_DELIVERY_PROTOCOL.md'));

        $new_section = $this->extractDeliveryProtocolSafeSyncSection($content);

        // The delivery protocol must describe Safe Sync as an optional
        // Enhanced Safety capability, not an "advanced / paid-tier add-on",
        // and must explicitly mark commercial packaging as UNDECIDED.
        $this->assertStringContainsString('Optional "Enhanced Safety" candidate', $new_section);
        $this->assertStringNotContainsString('advanced / paid-tier add-on', $new_section);
        $this->assertStringNotContainsString('optional or paid, never as baseline', $new_section);
        $this->assertStringContainsString('Commercial packaging, pricing, and paid tiers are explicitly', $new_section);
        $this->assertStringContainsString('UNDECIDED and out of scope of this delivery protocol', $new_section);
    }

    #[Test]
    public function domain_model_documents_moduleless_by_default_rebaseline(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('#### Magento V1 Moduleless-by-default Stop-and-Amend', $content);
        $this->assertStringContainsString('[Resolved — Post-#168 / Post-D6 rebaseline — 2026-09-03]', $content);
        $this->assertStringContainsString('Standard Magento / Adobe Commerce V1 is **MODULELESS BY DEFAULT**', $content);
        $this->assertStringContainsString('first-party `B2BPlatform_MagentoSafeSync` Composer component', $content);
        $this->assertStringContainsString('Optional "Enhanced Safety" candidate', $content);
        $this->assertStringContainsString('never** a precondition for basic read, mapping, preview', $content);
        $this->assertStringContainsString('Stock public WRITE is direction, not yet support truth', $content);
        $this->assertStringContainsString('Re-scope of Decision 6 (PHP / Adobe certification matrix)', $content);
        $this->assertStringContainsString('Connection truth (re-statement)', $content);
        $this->assertStringContainsString('Inventory presence does not mean support', $content);
        $this->assertStringContainsString('Narrow distinction for the current runtime owner', $content);
    }

    #[Test]
    public function domain_model_moduleless_rebaseline_preserves_decision_6_php_adobe_matrix(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        // The pre-existing Decision 6 wording must still exist (re-scoped,
        // not removed).
        $this->assertStringContainsString('UPGRADE-COMPATIBILITY ONLY — not a production support claim', $content);
        $this->assertStringContainsString('PREVIOUS CERTIFIED TARGET', $content);
        $this->assertStringContainsString('Safe Sync component readiness (Resolved — 2026-08-30)', $content);

        // The rebaseline must explicitly say Decision 6 is re-scoped, not
        // silently widened, and not silently removed.
        $this->assertStringContainsString(
            'Decision 6\'s PHP / Adobe certification matrix is **re-scoped**',
            $content,
        );
        $this->assertStringContainsString(
            'This amendment does **not** widen or relax the current Composer',
            $content,
        );
    }

    #[Test]
    public function domain_model_moduleless_rebaseline_preserves_public_support_false(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('Adobe Products / Export / Live | **FALSE**', $content);
        $this->assertStringContainsString('| Merchant consequential Live | **NOT EXPOSED** |', $content);
        $this->assertStringContainsString('| Deployment | **NOT PERFORMED** |', $content);
    }

    #[Test]
    public function domain_model_moduleless_rebaseline_records_intentional_runtime_gap(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('current runtime still consumes the entity-bound Safe Sync primitive', $content);
        $this->assertStringContainsString('This intentionally creates a current-runtime-vs-new-contract gap', $content);
        $this->assertStringContainsString('not** authorised by it', $content);
    }

    #[Test]
    public function delivery_protocol_records_optional_safe_sync_future_certification_contract(): void
    {
        $content = File::get(base_path('docs/09-CONNECTOR_DELIVERY_PROTOCOL.md'));

        $this->assertStringContainsString('## 12. Optional Safe Sync — future comparative certification contract', $content);
        $this->assertStringContainsString('[Resolved — Post-#168 rebaseline — 2026-09-03]', $content);
        $this->assertStringContainsString('Optional "Enhanced Safety" candidate', $content);
        $this->assertStringContainsString('documented comparative', $content);
        $this->assertStringContainsString('stock public REST API does', $content);
        $this->assertStringContainsString('not provide for the target operation', $content);
        $this->assertStringContainsString('operational cost', $content);
        $this->assertStringContainsString('materially affects the Product Goal', $content);
        $this->assertStringContainsString('smallest possible deployment surface', $content);
        $this->assertStringContainsString('re-position the first-party component', $content);
    }

    #[Test]
    public function delivery_protocol_optional_safe_sync_does_not_widen_composer_envelope(): void
    {
        $content = File::get(base_path('docs/09-CONNECTOR_DELIVERY_PROTOCOL.md'));

        $this->assertStringContainsString(
            'This section does **not** widen, relax, or narrow the current',
            $content,
        );
        $this->assertStringContainsString('Composer compatibility of the first-party component', $content);
    }

    #[Test]
    public function connector_ux_contract_documents_connection_confidence_and_one_next_step(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('## 19. Connector Account Overview — connection confidence and one next step', $content);
        $this->assertStringContainsString('[Resolved — production-validation rebaseline — 2026-09-05]', $content);
        $this->assertStringContainsString('**connection confidence + one next step**', $content);
        $this->assertStringContainsString('🟢 Підключено', $content);
        $this->assertStringContainsString('**`Створити пробну синхронізацію`**', $content);
        $this->assertStringNotContainsString('### 19.4 "Що ми перевірили"', $content);
    }

    #[Test]
    public function connector_ux_contract_freeze_rejects_field_count_as_completeness_proof(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('catalogue/field/image counts are not health KPIs', $content);
        $this->assertStringContainsString('They are **not** permanent rows on a', $content);
        $this->assertStringContainsString('Mapping / Available Fields', $content);
        $this->assertStringContainsString('Preview/worklists', $content);
    }

    #[Test]
    public function connector_ux_contract_freeze_protects_connection_truth_independence(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('Connection truth is independent from downstream capability truth', $content);
        $this->assertStringContainsString('must not turn an otherwise', $content);
        $this->assertStringContainsString('a Magento ACL denial', $content);
        $this->assertStringContainsString('not bad-credentials evidence', $content);
    }

    #[Test]
    public function connector_ux_contract_freeze_rejects_layer_a_internal_terminology(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        // The freeze extends the §13 forbidden vocabulary to also cover
        // connector-component names, PHP / Composer, Decision 6, and
        // internal readiness / probe / handshake names on Layer A/B.
        $this->assertStringContainsString('Safe Sync internals', $content);
        $this->assertStringContainsString('framework or module', $content);
        $this->assertStringContainsString('raw payloads', $content);
        $this->assertStringContainsString('internal probe/handshake/readiness', $content);
    }

    #[Test]
    public function connector_ux_contract_freeze_preserves_preview_first(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('**`Пробна синхронізація не змінює', $content);
        $this->assertStringContainsString('Preview-first contracts remain authoritative', $content);
        $this->assertStringContainsString('never claims', $content);
    }

    #[Test]
    public function connector_ux_contract_freeze_exactly_one_primary_action(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('### 19.3 Exactly one next step', $content);
        $this->assertStringContainsString('**`Налаштувати синхронізацію`**', $content);
        $this->assertStringContainsString('**`Створити пробну синхронізацію`**', $content);
        $this->assertStringContainsString('is never empty.', $content);
        $this->assertStringContainsString('first consequential Live', $content);
    }

    #[Test]
    public function atlas_records_intentional_current_runtime_vs_new_contract_gap(): void
    {
        $content = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('## Current runtime vs new contract — intentional gap', $content);
        $this->assertStringContainsString('Current code still consumes the entity-bound Safe Sync primitive', $content);
        $this->assertStringContainsString('The Composer compatibility envelope of the first-party component', $content);
        $this->assertStringContainsString('**not** widened by the new contract', $content);
        $this->assertStringContainsString('Until then, the Atlas must', $content);
    }

    #[Test]
    public function field_matrix_records_narrow_distinction_between_current_owner_and_target_arch(): void
    {
        $content = File::get(base_path('docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md'));

        $this->assertStringContainsString('## Current runtime owner vs newly approved target architecture', $content);
        $this->assertStringContainsString('[Recorded — 2026-09-03]', $content);
        $this->assertStringContainsString('`AdobeSafeSyncClient::writeSimpleProduct(...)`', $content);
        $this->assertStringContainsString('Newly approved target architecture (direction only — not runtime)', $content);
        $this->assertStringContainsString('Moduleless by default', $content);
        $this->assertStringContainsString('does **not** introduce new support rows', $content);
    }

    #[Test]
    public function field_matrix_preserves_inventory_presence_does_not_mean_support(): void
    {
        $content = File::get(base_path('docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md'));

        $this->assertStringContainsString('Inventory presence does **not** mean current connector support', $content);
        $this->assertStringContainsString('Completeness is no longer proven by a magic stable-field count', $content);
        $this->assertStringContainsString('Public support truth remains unchanged: `Adobe Products / Export / Live = false`', $content);
    }

    #[Test]
    public function rebaseline_is_docs_only_and_introduces_no_runtime_or_schema_change(): void
    {
        // The rebaseline itself must explicitly disclaim migrations,
        // new tables, new enums, Composer widening, support flip, real
        // mutation, and deployment in every authoritative place.
        $domain = File::get(base_path('docs/03-DOMAIN_MODEL.md'));
        $delivery = File::get(base_path('docs/09-CONNECTOR_DELIVERY_PROTOCOL.md'));
        $ux = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('no migrations, no new tables, no', $domain);
        $this->assertStringContainsString('new enums, no new runtime services, no Composer range change, and no', $domain);
        $this->assertStringContainsString('support truth flip', $domain);

        $this->assertStringContainsString('claim that the underlying runtime migration is already complete', $ux);

        $this->assertStringContainsString('widen, relax, or narrow the current', $delivery);
        $this->assertStringContainsString('Composer compatibility of the first-party component', $delivery);
    }

    #[Test]
    public function ux_contract_freeze_first_live_is_first_earliest_point_not_only_point(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $section = $this->extractSection($content, '### 19.3 Exactly one next step', '### 19.4');
        $normalized_section = $this->normalizeDocWhitespace($section);

        // The corrected wording: first / earliest point at which a real
        // Magento mutation MAY occur on the standard merchant journey.
        // Later approved Live syncs continue to be governed by existing
        // Sync runtime contracts.
        $this->assertStringContainsString('first consequential Live', $section);
        $this->assertStringContainsString('only after qualifying Preview evidence', $section);
        $this->assertStringContainsString('every existing Live support, authorization, and admission gate', $normalized_section);

        // The "only point" / "only ever allowed" framing is forbidden.
        $this->assertStringNotContainsString('the only point at which a real Magento mutation is allowed', $section);
    }

    #[Test]
    public function ux_contract_freeze_does_not_invent_fresh_preview_acknowledgement_rule(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $section = $this->extractSection($content, '### 19.3 Exactly one next step', '### 19.4');

        // The correction removed the invented fresh-Preview / explicit
        // merchant-acknowledgement requirement between Preview and the
        // first real Live.
        $this->assertStringNotContainsString('requires a fresh Preview reference', $section);
        $this->assertStringNotContainsString('merchant\n  acknowledgement that the most recent Preview is still valid', $section);
        $this->assertStringNotContainsString('most recent Preview is still valid', $section);

        $this->assertStringContainsString('this Overview contract neither bypasses nor', $section);
        $this->assertStringContainsString('redefines those owners', $section);
    }

    #[Test]
    public function ux_contract_recheck_is_conditional_secondary_action_not_always_available(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $section_19_1 = $this->extractSection($content, '### 19.1 Normal healthy presentation', '### 19.2');

        // The "[Перевірити ще раз]" control is now a conditional secondary
        // action that follows existing authorization / capability /
        // action-state rules, NOT literally always available.
        $this->assertStringNotContainsString('always available to a connected merchant', $section_19_1);
        $this->assertStringNotContainsString('always available as a secondary action', $section_19_1);
        $this->assertStringContainsString('under existing authorization and active-check rules', $section_19_1);
        $this->assertStringContainsString('must never mutate Magento', $section_19_1);
    }

    #[Test]
    public function ux_contract_does_not_gate_truth_flip_on_mandatory_first_party_safe_sync_component(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringNotContainsString(
            'truthful flip of Adobe Products/Export/Live advertised support still requires:',
            $content,
        );

        $this->assertStringNotContainsString('Until both land', $content);

        $link_first = $this->extractSection($content, '### Link-first necessary but not sufficient', '---');

        $this->assertStringNotContainsString('Truth flip waits for proven', $link_first);
        $this->assertStringNotContainsString('Stage 3E runtime blocker', $link_first);

        $this->assertStringContainsString('real-target certification', $content);
        $this->assertStringContainsString('actual standard shipping implementation', $content);
        $this->assertStringContainsString('optional Enhanced Safety primitive', $content);
        $this->assertStringContainsString(
            'is **not** a mandatory product prerequisite under the Post-#168 / Post-D6 moduleless-by-default decision',
            $content,
        );

        $this->assertStringContainsString('real-target certification', $link_first);
        $this->assertStringContainsString('actual consequential WRITE', $link_first);
    }

    #[Test]
    public function field_matrix_target_arch_keeps_seam_separation_no_mapping_consumes_stock_rest(): void
    {
        $content = File::get(base_path('docs/connectors/adobe-commerce/MAGENTO_V1_PRODUCT_FIELD_MATRIX.md'));

        $section = $this->extractSection(
            $content,
            '### Newly approved target architecture (direction only — not runtime)',
            '### Narrow distinction this record preserves',
        );
        $normalized_section = $this->normalizeDocWhitespace($section);

        // The corrected Field Matrix must keep the three seams separate:
        // - Magento stock API is the connector remote transport.
        // - Mapping is a platform-owned workflow over persisted metadata.
        // - Preview is a platform-owned orchestration under existing contracts.
        $this->assertStringContainsString('**Target seam separation**', $section);
        $this->assertStringContainsString('**Magento stock API** is the **connector remote transport**', $section);
        $this->assertStringContainsString('**Mapping** is a **platform-owned workflow**', $section);
        $this->assertStringContainsString('persisted and normalised discovered metadata', $section);
        $this->assertStringContainsString('Mapping does **not** itself', $section);
        $this->assertStringContainsString('consume vendor stock REST as a runtime', $section);
        $this->assertStringContainsString('**Preview** is a **platform-owned orchestration**', $section);
        $this->assertStringContainsString('bounded remote reads', $normalized_section);

        // The earlier lumped "Stock public REST as default runtime" bullet
        // (which falsely implied Mapping and Preview themselves consume
        // vendor stock REST) is removed from the new section.
        $this->assertStringNotContainsString('**Stock public REST as default runtime**', $section);
        $this->assertStringNotContainsString('expected to consume vendor stock public REST for connection, READ,', $section);
    }

    /**
     * Extract the Connector Benchmark-First Rule section.
     */
    private function extractConnectorBenchmarkFirstRule(string $content): string
    {
        return $this->extractSection(
            $content,
            '## Connector Benchmark-First Rule',
            '## User Interface and Terminology Rules',
        );
    }

    /**
     * Extract the new "Magento V1 Moduleless-by-default Stop-and-Amend"
     * subsection (post-#168 / post-D6 rebaseline).
     */
    private function extractMagentoV1ModulelessSection(string $content): string
    {
        return $this->extractSection(
            $content,
            '#### Magento V1 Moduleless-by-default Stop-and-Amend',
            "\n#### ",
        );
    }

    private function normalizeDocWhitespace(string $content): string
    {
        return preg_replace('/\s+/u', ' ', trim($content));
    }

    /**
     * Extract the new §12 "Optional Safe Sync — future comparative
     * certification contract" section in the delivery protocol.
     */
    private function extractDeliveryProtocolSafeSyncSection(string $content): string
    {
        return $this->extractSection(
            $content,
            '## 12. Optional Safe Sync — future comparative certification contract',
            '## Final Rule',
        );
    }

    /**
     * Generic section extractor. Returns the text between $start and
     * $stopAnchor, inclusive of $start. Used to scope assertions so that
     * unrelated "paid" / "advanced" / etc. usage in other product docs
     * is not picked up.
     */
    private function extractSection(string $content, string $start, string $stopAnchor): string
    {
        $start_pos = strpos($content, $start);
        if ($start_pos === false) {
            $this->fail("Section start not found: {$start}");
        }

        $after_start = substr($content, $start_pos + strlen($start));
        $stop_pos = strpos($after_start, $stopAnchor);
        if ($stop_pos === false) {
            // No later anchor → take a bounded tail of the file.
            return substr($content, $start_pos, 20000);
        }

        return substr($content, $start_pos, strlen($start) + $stop_pos);
    }
}
