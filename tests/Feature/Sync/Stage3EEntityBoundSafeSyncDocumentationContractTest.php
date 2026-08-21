<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Stage3EEntityBoundSafeSyncDocumentationContractTest extends TestCase
{
    #[Test]
    public function domain_model_documents_stage_3e_entity_bound_safe_sync_contract_section(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '##### Stage 3E Stop-and-Amend — Magento ownership and entity-bound Safe Sync runtime contract',
            $content,
        );
        $this->assertStringContainsString('[Resolved — Stage 3E docs contract]', $content);
    }

    #[Test]
    public function stage_3e_docs_contract_is_frozen_without_runtime_authorization(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('**Docs-only in this PR.**', $section);
        $this->assertStringContainsString('No Magento module runtime', $section);
        $this->assertStringContainsString('No migration in this docs-only PR', $section);
        $this->assertStringContainsString('Adobe Products/Export/Live | **FALSE**', $section);
        $this->assertStringContainsString('Replacement runtime follows in a separate follow-on PR', $section);
    }

    #[Test]
    public function first_party_magento_component_is_entity_bound_read_write_boundary(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('separate Composer', $section);
        $this->assertStringContainsString('magento2-module', $section);
        $this->assertStringContainsString('Entity-bound **read + write** boundary', $section);
        $this->assertStringContainsString('**No** Magento core modification', $section);
        $this->assertStringContainsString('**No** direct SaaS→Magento DB access path', $section);
        $this->assertStringContainsString('**No** broad/global Product interceptors', $section);
    }

    #[Test]
    public function entity_trust_uses_entity_id_and_forbids_post_trust_sku_get_proof(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('ENTITY TRUST', $section);
        $this->assertStringContainsString('stored Magento logical `entity_id`', $section);
        $this->assertStringContainsString('mandatory equality precondition', $section);
        $this->assertStringContainsString('**not** identity authority', $section);
        $this->assertStringContainsString(
            'stock SKU GET (`GET /V1/products/:sku`) must **not** participate in',
            $section,
        );
        $this->assertStringContainsString('verification, reconciliation, or applied-state proof', $section);
    }

    #[Test]
    public function entity_bound_mutation_boundary_locks_identifier_field_and_verifies_sku(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('#### Entity-bound mutation boundary (frozen)', $section);
        $this->assertStringContainsString('`getIdentifierField()`', $section);
        $this->assertStringContainsString('**not** physical `getLinkField()`', $section);
        $this->assertStringContainsString('`getById(..., forceReload=true)`', $section);
        $this->assertStringContainsString('**no** target re-resolution by SKU', $section);
        $this->assertStringContainsString('Magento `Sku::beforeSave()` may silently suffix', $section);
        $this->assertStringContainsString('Post-save exact SKU equality is therefore', $section);
        $this->assertStringContainsString('**load-bearing**', $section);
        $this->assertStringContainsString('create fallback structurally impossible', $section);
    }

    #[Test]
    public function content_staging_locks_logical_entity_id_not_row_id(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('#### Content Staging (frozen)', $section);
        $this->assertStringContainsString('pending scheduled update', $section);
        $this->assertStringContainsString('**never** define logical identity with `getLinkField()` / `row_id`', $section);
        $this->assertStringContainsString('**no** dependency on Commerce-only `VersionManager`', $section);
    }

    #[Test]
    public function galera_requires_causal_current_entity_bound_reads(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('#### Galera / multi-node concurrency (frozen)', $section);
        $this->assertStringContainsString('gap locks are **defence-in-depth only**', $section);
        $this->assertStringContainsString('causal-current / read-after-write', $section);
        $this->assertStringContainsString('stock SKU lookup may be used **only** as pre-trust candidate discovery', $section);
    }

    #[Test]
    public function safe_primitives_forbid_sku_addressed_operations_after_binding(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('#### Safe mutation primitives (frozen)', $section);
        $this->assertStringContainsString('SKU-addressed Product / media / configurable operations', $section);
        $this->assertStringContainsString('**forbidden** for safety decisions', $section);
        $this->assertStringContainsString('SKU-addressed `GalleryManagement` operations', $section);
    }

    #[Test]
    public function rollback_requires_callback_pool_cleanup_and_internal_seam(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('#### Rollback and EntityManager callbacks (frozen)', $section);
        $this->assertStringContainsString('pending `EntityManager` callbacks', $section);
        $this->assertStringContainsString('must be **cleared**', $section);
        $this->assertStringContainsString('one narrowly isolated Magento internal compatibility seam', $section);
        $this->assertStringContainsString('uses only public `@api` contracts', $section);
    }

    #[Test]
    public function media_boundary_excludes_destructive_delete_in_v1(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('#### Media transactional boundary (frozen)', $section);
        $this->assertStringContainsString('**not** transactionally rolled back with DB', $section);
        $this->assertStringContainsString('**no** destructive media DELETE/cleanup subsystem in V1', $section);
        $this->assertStringContainsString('**entity-bound** media read with bounded', $section);
        $this->assertStringContainsString('response size', $section);
    }

    #[Test]
    public function account_readiness_separates_static_support_from_fresh_runtime_readiness(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('`ConnectorSyncOperationSupport`', $section);
        $this->assertStringContainsString('`ConnectorLiveRuntimeReadiness`', $section);
        $this->assertStringContainsString('Static software capability', $section);
        $this->assertStringContainsString('Fresh account-specific remote prerequisite', $section);
        $this->assertStringContainsString('no readiness table', $section);
        $this->assertStringContainsString('no persisted handshake evidence on `SyncRunItem`', $section);
        $this->assertStringContainsString('**outside** the DB admission transaction', $section);
        $this->assertStringContainsString('`SyncRunConsequentialWriteGate`', $section);
    }

    #[Test]
    public function failure_semantics_preserve_known_states_and_identity_mismatch_reason(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('`KnownApplied`', $section);
        $this->assertStringContainsString('`KnownNotApplied`', $section);
        $this->assertStringContainsString('`UnknownOrAmbiguous`', $section);
        $this->assertStringContainsString('**IdentityMismatch** is a **reason beneath `KnownNotApplied`**', $section);
        $this->assertStringContainsString('**no** blind retry', $section);
        $this->assertStringContainsString('bridge-authored response', $section);
    }

    #[Test]
    public function auto_create_is_out_of_v1_with_zero_intended_mutation(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('#### Auto-create (OUT of V1 — frozen)', $section);
        $this->assertStringContainsString('**zero intended mutation**', $section);
        $this->assertStringContainsString('repository fallback cannot turn a linked update into', $section);
    }

    #[Test]
    public function validation_harness_requires_new_real_target_proofs(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('silent SKU suffix rollback', $section);
        $this->assertStringContainsString('CallbackPool', $section);
        $this->assertStringContainsString('Content Staging scheduled-version', $section);
        $this->assertStringContainsString('cross-node duplicate SKU', $section);
        $this->assertStringContainsString('causal cross-node entity read', $section);
        $this->assertStringContainsString('repository create-fallback guard', $section);
        $this->assertStringContainsString('transport loss around COMMIT', $section);
        $this->assertStringContainsString('`APP_ENV=stage3e-validation`', $section);
    }

    #[Test]
    public function stage_status_documents_docs_contract_done_and_runtime_pending(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('3E docs contract | **Done (docs contract)**', $section);
        $this->assertStringContainsString('3E runtime + validation | **Pending**', $section);
        $this->assertStringContainsString('Adobe Products/Export/Live | **FALSE**', $section);
        $this->assertStringContainsString('No Stage 3F', $section);
    }

    #[Test]
    public function implementation_gaps_documents_stage_3e_docs_contract_done(): void
    {
        $gaps = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('**Stage 3E — Real Adobe Validation + Truth Flip**', $gaps);
        $this->assertStringContainsString('**Done (docs contract)**', $gaps);
        $this->assertStringContainsString('entity-bound Safe Sync runtime contract frozen', $gaps);
        $this->assertStringContainsString('support remains **false**', $gaps);
    }

    #[Test]
    public function atlas_documents_stage_3e_docs_contract_row(): void
    {
        $atlas = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('| Stage 3E entity-bound Safe Sync contract (docs) | DOCS CONTRACT DONE — runtime pending |', $atlas);
        $this->assertStringContainsString('Stage3EEntityBoundSafeSyncDocumentationContractTest.php', $atlas);
    }

    #[Test]
    public function ux_contract_references_entity_bound_safe_sync_contract(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('## 18. Per-item Live linking (Resolved — Stage 3E docs contract)', $content);
        $this->assertStringContainsString('entity-bound Safe Sync runtime contract', $content);
        $this->assertStringContainsString('first-party Magento entity-bound Safe Sync component', $content);
    }

    #[Test]
    public function ui_design_system_references_entity_bound_safe_sync_contract(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('### Per-item Live linking (Resolved — Stage 3E docs contract)', $content);
        $this->assertStringContainsString('entity-bound Safe Sync runtime contract', $content);
        $this->assertStringContainsString('`ConnectorLiveRuntimeReadiness`', $content);
    }

    #[Test]
    public function merchant_admission_gates_include_connector_live_runtime_readiness(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/##### Merchant consequential Live admission gates \(frozen — Stage 3-0\)\n\n(.*?)(?=\n`run_sync_live` means)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate merchant consequential Live admission gates section');
        }

        $section = $matches[1];

        $this->assertStringContainsString('fresh `ConnectorLiveRuntimeReadiness`', $section);
        $this->assertStringContainsString('Do **not** persist handshake evidence into `SyncRunItem`', $section);
    }

    /**
     * @return non-empty-string
     */
    private function stage3eContractSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/##### Stage 3E Stop-and-Amend — Magento ownership and entity-bound Safe Sync runtime contract\n\n(.*?)(?=\n#### Merchant Preview Authorization)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Stage 3E contract section in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }
}
