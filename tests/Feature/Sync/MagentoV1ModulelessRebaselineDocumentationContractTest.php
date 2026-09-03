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
        $this->assertStringContainsString('AI / architect MUST first', $content);
        $this->assertStringContainsString('inspect the vendor\'s supported stock API / official integration mechanism', $content);
        $this->assertStringContainsString('at least two relevant mature SaaS, iPaaS, PIM, or marketplace', $content);
        $this->assertStringContainsString('AI reasoning by itself is NEVER sufficient justification', $content);
        $this->assertStringContainsString('An installed first-party component remains legitimate', $content);
        $this->assertStringContainsString('triggers the Stop and Amend Rule', $content);
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
    public function connector_ux_contract_documents_verify_to_preview_journey_freeze(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('## 19. Connector Account Overview — verify-to-preview journey freeze', $content);
        $this->assertStringContainsString('[Resolved — Post-#168 / Post-D6 rebaseline — 2026-09-03]', $content);
        $this->assertStringContainsString('CONNECT', $content);
        $this->assertStringContainsString('safe VERIFY', $content);
        $this->assertStringContainsString('"Що ми перевірили"', $content);
        $this->assertStringContainsString('ONE next action', $content);
        $this->assertStringContainsString('"Створити пробну синхронізацію"', $content);
        $this->assertStringContainsString('"Виконати першу синхронізацію"', $content);
    }

    #[Test]
    public function connector_ux_contract_freeze_rejects_field_count_as_completeness_proof(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('Do not use the magic headline field count as proof of connector', $content);
        $this->assertStringContainsString('N is a count, not a certification', $content);
        $this->assertStringContainsString('An **empty catalogue is neutral**', $content);
        $this->assertStringContainsString('`Каталог поки порожній`', $content);
        $this->assertStringContainsString('"Пробна синхронізація не змінює дані в Magento" reassurance is', $content);
    }

    #[Test]
    public function connector_ux_contract_freeze_protects_connection_truth_independence(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('A successful baseline connection MUST NOT become red merely because', $content);
        $this->assertStringContainsString('field-metadata permission is missing on the target', $content);
        $this->assertStringContainsString('A Magento ACL denial', $content);
        $this->assertStringContainsString('bad credentials', $content);
    }

    #[Test]
    public function connector_ux_contract_freeze_rejects_layer_a_internal_terminology(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        // The freeze extends the §13 forbidden vocabulary to also cover
        // connector-component names, PHP / Composer, Decision 6, and
        // internal readiness / probe / handshake names on Layer A/B.
        $this->assertStringContainsString('"Safe Sync"', $content);
        $this->assertStringContainsString('PHP / Composer strings', $content);
        $this->assertStringContainsString('Decision 6 wording', $content);
        $this->assertStringContainsString('internal readiness / probe / handshake', $content);
    }

    #[Test]
    public function connector_ux_contract_freeze_preserves_preview_first(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('"Пробна синхронізація не змінює дані в Magento"', $content);
        $this->assertStringContainsString('Preview-first architecture is preserved', $content);
        $this->assertStringContainsString('MUST NOT show a "Синхронізація працює"', $content);
    }

    #[Test]
    public function connector_ux_contract_freeze_exactly_one_primary_action(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('### 19.6 Exactly one next action (adaptive sequence)', $content);
        $this->assertStringContainsString('**`Налаштувати синхронізацію`**', $content);
        $this->assertStringContainsString('**`Створити пробну синхронізацію`**', $content);
        $this->assertStringContainsString('**`Виконати першу синхронізацію`**', $content);
        $this->assertStringContainsString('Do not show all three at once', $content);
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
}
