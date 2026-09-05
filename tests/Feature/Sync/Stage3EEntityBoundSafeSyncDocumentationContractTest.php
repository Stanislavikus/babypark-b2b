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

        $this->assertStringContainsString('original contract landed as a', $section);
        $this->assertStringContainsString('docs-only', $section);
        $this->assertStringContainsString('trusted simple entity-bound Product WRITE', $section);
        $this->assertStringContainsString('consumption', $section);
        $this->assertStringContainsString('validation-only disposable validation harness', $section);
        $this->assertStringContainsString('**Implemented in Stage 3E-R2a.**', $section);
        $this->assertStringContainsString('Adobe Products/Export/Live | **FALSE**', $section);
        $this->assertStringContainsString('no real-target validation harness execution/certification or deployment has', $section);
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
    public function validation_harness_status_heading_marks_harness_implemented_real_target_pending(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString(
            '#### Validation harness contract (frozen — harness implemented; real-target proofs pending)',
            $section,
        );
        $this->assertStringContainsString('harness implemented; real-target proofs pending', $section);
    }

    #[Test]
    public function certification_abort_disambiguation_freezes_three_distinct_scenarios(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString(
            '##### Certification abort disambiguation (frozen — Step-4 arbitration)',
            $section,
        );
        $this->assertStringContainsString('Certification abort disambiguation', $section);
        $this->assertStringContainsString('Do **not** reintroduce "brute-force abort" as a normative term', $section);
        $this->assertStringContainsString('transport loss around COMMIT', $section);

        $this->assertStringContainsString('**A. Worker termination around COMMIT**', $section);
        $this->assertStringContainsString('Worker termination around COMMIT', $section);
        $this->assertStringContainsString('caller classifies the result as `UnknownOrAmbiguous`', $section);
        $this->assertStringContainsString('**no** automatic consequential retry occurs', $section);
        $this->assertStringContainsString('durable target state is independently reconciled afterward', $section);
        $this->assertStringContainsString('Do **not** require a later request to reuse "the same connection"', $section);

        $this->assertStringContainsString('**B. DB session loss around COMMIT**', $section);
        $this->assertStringContainsString('DB session loss around COMMIT', $section);
        $this->assertStringContainsString('ambiguous state remains `UnknownOrAmbiguous`', $section);
        $this->assertStringContainsString('quarantined / reset before', $section);
        $this->assertStringContainsString('must **not** inherit poisoned transaction', $section);

        $this->assertStringContainsString('**C. Transport loss around COMMIT**', $section);
        $this->assertStringContainsString('Keep this separate from A and B', $section);
        $this->assertStringContainsString('target response completed at the delegate boundary', $section);
        $this->assertStringContainsString('read-only reconciliation only', $section);
        $this->assertStringContainsString('does **not** by itself prove the instant of physical DB COMMIT', $section);
    }

    #[Test]
    public function decision_7_stock_reachability_documents_callback_pool_facts_and_step4_proofs(): void
    {
        $section = $this->post168AmendmentSection();

        $this->assertStringContainsString('**Stock reachability (frozen — Step-4 arbitration):**', $section);
        $this->assertStringContainsString('Stock reachability', $section);
        $this->assertStringContainsString('Magento 2.4.9 and 2.4.8-p5', $section);
        $this->assertStringContainsString('Magento\Framework\Model\ExecuteCommitCallbacks', $section);
        $this->assertStringContainsString('ExecuteCommitCallbacks', $section);
        $this->assertStringContainsString('Magento\Framework\DB\Adapter\AdapterInterface', $section);
        $this->assertStringContainsString('`CallbackPool::get`', $section);
        $this->assertStringContainsString('CallbackPool::get', $section);
        $this->assertStringContainsString('`afterRollBack()` clears `CallbackPool` for the same adapter hash', $section);

        $this->assertStringContainsString('Ordinary stock Magento Product callback exceptions do **NOT** normally', $section);
        $this->assertStringContainsString('`safe_sync_post_commit_callback_failed`', $section);
        $this->assertStringContainsString('VALIDATION-ONLY fault', $section);
        $this->assertStringContainsString('Product durable state stays `KnownApplied` and a warning is', $section);
        $this->assertStringContainsString("this proves the Safe Sync bridge's", $section);
        $this->assertStringContainsString(
            'it is **NOT** a claim that stock Magento',
            $section,
        );
        $this->assertStringContainsString('Product callback exceptions naturally', $section);
        $this->assertStringContainsString('Do **not** change the applied-state enum', $section);
    }

    #[Test]
    public function decision_2_image_role_scope_requires_gallery_and_eav_evidence(): void
    {
        $section = $this->post168AmendmentSection();

        $this->assertStringContainsString('**Image-role scope requirement (frozen — Step-4 arbitration):**', $section);
        $this->assertStringContainsString('`Magento\ProductRepository::save()`', $section);
        $this->assertStringContainsString('store-scope', $section);
        $this->assertStringContainsString('normalization logic', $section);

        $this->assertStringContainsString('`image`', $section);
        $this->assertStringContainsString('`small_image`', $section);
        $this->assertStringContainsString('`thumbnail`', $section);

        $this->assertStringContainsString('**A. gallery state:**', $section);
        $this->assertStringContainsString('gallery identity / value', $section);
        $this->assertStringContainsString('**B. image-role EAV state:**', $section);
        $this->assertStringContainsString('default / admin scope representation', $section);
        $this->assertStringContainsString('exact certification Store View scope', $section);
        $this->assertStringContainsString('Gallery-only comparison is **INSUFFICIENT**', $section);
        $this->assertStringContainsString('Gallery-only', $section);
        $this->assertStringContainsString('INSUFFICIENT', $section);
        $this->assertStringContainsString('Do **not** claim this is media WRITE certification', $section);
        $this->assertStringContainsString('Full media WRITE', $section);
        $this->assertStringContainsString('Decision 9 step 9', $section);
    }

    #[Test]
    public function real_adobe_validation_gate_references_certification_abort_disambiguation_subsection(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/##### Real Adobe validation gate\n\n(.*?)(?=\n##### Transport contract)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Real Adobe validation gate section in 03-DOMAIN_MODEL.md');
        }

        $section = $matches[1];

        $this->assertStringContainsString('Certification abort disambiguation', $section);
        $this->assertStringContainsString('worker termination around COMMIT', $section);
        $this->assertStringContainsString('DB session loss around', $section);
        $this->assertStringContainsString('transport loss around COMMIT', $section);
    }

    #[Test]
    public function stage_status_documents_docs_contract_done_and_runtime_pending(): void
    {
        $section = $this->stage3eContractSection();

        $this->assertStringContainsString('3E docs contract | **Done (docs contract)**', $section);
        $this->assertStringContainsString('3E runtime + validation | **Partially implemented (internal; validation-only)**', $section);
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
        $this->assertStringContainsString('**simple trusted entity-bound Product WRITE consumption is implemented internally**', $gaps);
        $this->assertStringContainsString('support remains **false**', $gaps);
    }

    #[Test]
    public function atlas_documents_stage_3e_docs_contract_row(): void
    {
        $atlas = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('Stage 3E entity-bound Safe Sync contract (docs)', $atlas);
        $this->assertStringContainsString('DOCS CONTRACT DONE — runtime pending', $atlas);
        $this->assertStringContainsString('Stage 3E Magento Safe Sync read + simple trusted write consumption', $atlas);
        $this->assertStringContainsString('IMPLEMENTED (internal; support false; simple path consumed; not real-target certified)', $atlas);
        $this->assertStringContainsString('Stage3EEntityBoundSafeSyncDocumentationContractTest.php', $atlas);
    }

    #[Test]
    public function ux_contract_references_entity_bound_safe_sync_contract(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('## 18. Per-item Live linking (Resolved — Stage 3E docs contract)', $content);
        $this->assertStringContainsString('entity-bound Safe Sync runtime contract', $content);
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

    #[Test]
    public function post_168_amendment_section_is_present_and_marked_docs_only(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '##### Stage 3E Post-#168 Real-Target Certification Amendment (docs only)',
            $content,
        );
        $this->assertStringContainsString('[Resolved — Stage 3E post-#168 docs amendment]', $content);
        $this->assertStringContainsString('No runtime PHP under `app/` or', $content);
        $this->assertStringContainsString('No `composer.json`', $content);
        $this->assertStringContainsString('validation-only Laravel control plane', $content);
        $this->assertStringContainsString('Live was not enabled', $content);
        $this->assertStringContainsString('broad Stage 3F was created', $content);
    }

    #[Test]
    public function decision_1_records_current_state_without_overstating_readiness(): void
    {
        $section = $this->post168AmendmentSection();

        $this->assertStringContainsString('#### DECISION 1 — Current state (frozen)', $section);
        $this->assertStringContainsString('An isolated entity-bound simple Product **WRITE** primitive exists', $section);
        $this->assertStringContainsString('Laravel **Safe Sync write client**', $section);
        $this->assertStringContainsString('is consumed by the trusted simple', $section);
        $this->assertStringContainsString('does **not** route a trusted simple', $section);
        $this->assertStringContainsString('at most one', $section);
        $this->assertStringContainsString('consequential WRITE', $section);
        $this->assertStringContainsString('Historic SKU-addressed consequential writers remain separately in', $section);
        $this->assertStringContainsString('`ConnectorSyncOperationSupport(Products, Export, Live)` remains **false**', $section);
        $this->assertStringContainsString('No** real-target consequential WRITE certification has occurred', $section);
        $this->assertStringContainsString('trusted simple consumption is', $section);
        $this->assertStringContainsString('configurable/media completion and real-target evidence remain', $section);
        $this->assertStringContainsString('support remains false', $section);
    }

    #[Test]
    public function decision_2_requires_media_neutral_product_save(): void
    {
        $section = $this->post168AmendmentSection();

        $this->assertStringContainsString('#### DECISION 2 — Product save must be media-neutral (frozen)', $section);
        $this->assertStringContainsString('`media_gallery`', $section);
        $this->assertStringContainsString('MUST NOT** cause uncontrolled', $section);
        $this->assertStringContainsString('image-role mutation', $section);
        $this->assertStringContainsString('structurally exclude media state from the core Product save', $section);
        $this->assertStringContainsString('prove and enforce media neutrality', $section);
        $this->assertStringContainsString('**insufficient** as proof if', $section);
        $this->assertStringContainsString('controlled-field postcondition alone is', $section);
    }

    #[Test]
    public function decision_3_requires_connection_reset_quarantine_not_just_close(): void
    {
        $section = $this->post168AmendmentSection();

        $this->assertStringContainsString('#### DECISION 3 — Connection quarantine (frozen)', $section);
        $this->assertStringContainsString('merely closing the Magento DB connection is insufficient', $section);
        $this->assertStringContainsString('non-zero / poisoned', $section);
        $this->assertStringContainsString('reset / quarantine the exact', $section);
        $this->assertStringContainsString('shared entity connection**', $section);
        $this->assertStringContainsString('Current code facts (write-side seams implicated by the RED finding)', $section);
        $this->assertStringContainsString('`ProductWriteManagement::quarantineConnection()`', $section);
        $this->assertStringContainsString('`GaleraWriteSession::quarantineConnectionAfterRestoreFailure()`', $section);
        $this->assertStringContainsString('`GaleraSessionScope::quarantineConnectionAfterRestoreFailure()`', $section);
        $this->assertStringContainsString('analogous READ-side quarantine seam', $section);
        $this->assertStringContainsString('is **not** the sole or primary', $section);
        $this->assertStringContainsString('source of the write transaction-state finding', $section);
        $this->assertStringContainsString('module-local', $section);
        $this->assertStringContainsString('**target-version tested**', $section);
        $this->assertStringContainsString('Do not claim real-target proof already exists', $section);
    }

    #[Test]
    public function decision_4_marks_price_scale_6_as_contract_not_defect(): void
    {
        $section = $this->post168AmendmentSection();

        $this->assertStringContainsString('#### DECISION 4 — Price scale (frozen — not an open defect)', $section);
        $this->assertStringContainsString('scale 6** for', $section);
        $this->assertStringContainsString('`catalog_product_entity_decimal.value`', $section);
        $this->assertStringContainsString('fail-closed six-decimal admission', $section);
        $this->assertStringContainsString('Do **not** mark `PRICE_SCALE = 6` as an open defect', $section);
    }

    #[Test]
    public function decision_5_narrows_e12_to_one_explicit_store_view_code(): void
    {
        $section = $this->post168AmendmentSection();

        $this->assertStringContainsString('#### DECISION 5 — Store view context (frozen — amends E12 narrowly)', $section);
        $this->assertStringContainsString('one explicit Magento Store View code**', $section);
        $this->assertStringContainsString('`all` is **NOT** a V1 consequential WRITE target', $section);
        $this->assertStringContainsString('MUST NOT** silently fan out across all Store Views', $section);
        $this->assertStringContainsString('Default Store View', $section);
        $this->assertStringContainsString('own explicit execution contexts', $section);
        $this->assertStringContainsString('Automatic multi-Store-View fan-out within one V1 run is **OUT**', $section);
        $this->assertStringContainsString('Localized / store-scoped fan-out remains **OUT of first V1**', $section);
        $this->assertStringContainsString('Magento **Website** or **Store Group** names are never REST store codes', $section);
    }

    #[Test]
    public function decision_5_e12_narrow_amendment_is_present_in_e12_section(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/#### E12\. Multi-store \/ store-view scope\n\n(.*?)(?=\n#### E13\.)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate E12 section in 03-DOMAIN_MODEL.md');
        }

        $section = $matches[1];

        $this->assertStringContainsString('**Magento V1 freeze:**', $section);
        $this->assertStringContainsString('**Safe Sync consequential WRITE scope (Stage 3E post-#168 amendment — frozen):**', $section);
        $this->assertStringContainsString('narrows E12 for the Safe Sync path', $section);
        $this->assertStringContainsString('does not create a parallel rule', $section);
        $this->assertStringContainsString('one explicit Magento Store View code** per', $section);
        $this->assertStringContainsString('`all` is **NOT** a V1 consequential WRITE target', $section);
    }

    #[Test]
    public function decision_6_records_php_adobe_certification_matrix(): void
    {
        $section = $this->post168AmendmentSection();

        $this->assertStringContainsString('#### DECISION 6 — PHP / Adobe certification matrix (frozen)', $section);
        $this->assertStringContainsString('| **PRIMARY** | 2.4.9 | 8.5 |', $section);
        $this->assertStringContainsString('| **UPGRADE-COMPATIBILITY ONLY — not a production support claim** | 2.4.9 | 8.4 |', $section);
        $this->assertStringContainsString('| **PREVIOUS CERTIFIED TARGET** | 2.4.8-p5 | 8.4 |', $section);
        $this->assertStringContainsString('| **OUT OF V1 CERTIFICATION** | — | 8.3 |', $section);

        $this->assertStringContainsString('2026-08-30 Stop & Amend', $section);
        $this->assertStringContainsString('Adobe now lists PHP 8.5 for 2.4.9 production use', $section);
        $this->assertStringContainsString('describes PHP 8.4 as upgrade compatibility only', $section);
        $this->assertStringContainsString('This label correction does', $section);
        $this->assertStringContainsString(
            'not broaden or otherwise change the Safe Sync Composer constraints',
            $section,
        );
        $this->assertStringContainsString('PHP 8.3 is **OUT of V1 certification**', $section);

        $this->assertStringNotContainsString('| **SUPPORTED COMPATIBILITY** | 2.4.9 | 8.4 |', $section);
        $this->assertStringNotContainsString('PHP 8.4 **IS supported** on Adobe Commerce 2.4.9', $section);
    }

    #[Test]
    public function decision_7_preserves_known_applied_with_warning_no_new_enum(): void
    {
        $section = $this->post168AmendmentSection();

        $this->assertStringContainsString('#### DECISION 7 — Callback failure semantics (frozen)', $section);
        $this->assertStringContainsString('physical COMMIT precedes bridge-owned callback processing', $section);
        $this->assertStringContainsString('does NOT** downgrade durable', $section);
        $this->assertStringContainsString('response remains `KnownApplied` with a **warning**', $section);
        $this->assertStringContainsString('not a separate `KnownAppliedWithWarning` enum', $section);
        $this->assertStringContainsString('prove `CallbackPool` drain / failure behaviour', $section);
        $this->assertStringContainsString('`KnownApplied` / `KnownNotApplied` / `UnknownOrAmbiguous`', $section);
        $this->assertStringContainsString('is **unchanged** for this docs task', $section);
        $this->assertStringContainsString('post-COMMIT warnings', $section);
    }

    #[Test]
    public function decision_8_preserves_content_staging_rules_and_no_new_staged_version_semantics(): void
    {
        $section = $this->post168AmendmentSection();

        $this->assertStringContainsString('#### DECISION 8 — Content staging (frozen — no new semantics)', $section);
        $this->assertStringContainsString('Preserve the frozen Stage 3E Stop-and-Amend Content Staging rules', $section);
        $this->assertStringContainsString('**do not** invent a staged-version warning', $section);
        $this->assertStringContainsString('Logical `entity_id` identity is preserved', $section);
        $this->assertStringContainsString('All relevant physical rows are locked', $section);
        $this->assertStringContainsString('No Commerce-only `VersionManager` dependency', $section);
        $this->assertStringContainsString('pending scheduled update', $section);
        $this->assertStringContainsString('returns to architectural arbitration', $section);
    }

    #[Test]
    public function decision_9_records_12_step_pre_live_sequence_with_support_false(): void
    {
        $section = $this->post168AmendmentSection();

        $this->assertStringContainsString('#### DECISION 9 — Order of work (frozen pre-Live sequence)', $section);
        $this->assertStringContainsString('1. **Docs certification amendment**', $section);
        $this->assertStringContainsString('2. **Bounded Safe Sync module correction**', $section);
        $this->assertStringContainsString('3. **Disposable validation harness**', $section);
        $this->assertStringContainsString('4. **Isolated simple writer certification**', $section);
        $this->assertStringContainsString('5. **Content Staging proof**', $section);
        $this->assertStringContainsString('6. **Galera proof**', $section);
        $this->assertStringContainsString('7. **Entity-bound lifecycle**', $section);
        $this->assertStringContainsString('8. **Entity-bound configurable**', $section);
        $this->assertStringContainsString('9. **Entity-bound media**', $section);
        $this->assertStringContainsString('10. **`ConnectorLiveRuntimeReadiness` integration**', $section);
        $this->assertStringContainsString('11. **Live consumption**', $section);
        $this->assertStringContainsString('12. **Final truth-flip gate**', $section);
        $this->assertStringContainsString('**logical evidence gates**, not a per-item-PR', $section);
        $this->assertStringContainsString('**Support remains', $section);
    }

    #[Test]
    public function dormant_discrepancies_table_records_three_stock_sku_addressed_paths(): void
    {
        $section = $this->post168AmendmentSection();

        $this->assertStringContainsString('#### Code-vs-docs dormant discrepancies', $section);
        $this->assertStringContainsString('documented here, not fixed in this docs task', $section);
        $this->assertStringContainsString('Stock SKU-addressed **media** consequential path', $section);
        $this->assertStringContainsString('Stock SKU-addressed **configurable options / child link** path', $section);
        $this->assertStringContainsString('Stock SKU-addressed **lifecycle status** path', $section);
        $this->assertStringContainsString('production-unreachable**', $section);
        $this->assertStringContainsString('replaced before their respective Live path becomes', $section);
    }

    #[Test]
    public function post_168_status_table_keeps_support_false_and_lists_decision_9_steps(): void
    {
        $section = $this->post168AmendmentSection();

        $this->assertStringContainsString('#### Post-#168 status after this docs amendment', $section);
        $this->assertStringContainsString('| Stage 3E post-#168 certification amendment | **Done (docs only)** — 9 decisions recorded; no runtime change |', $section);
        $this->assertStringContainsString('| Disposable validation harness | **Implemented (internal; validation-only)** — Decision 9 step 3 landed; no real-target certification executed in this PR |', $section);
        $this->assertStringContainsString('| Adobe Products / Export / Live | **FALSE** |', $section);
        $this->assertStringContainsString('| Merchant consequential Live | **NOT EXPOSED** |', $section);
        $this->assertStringContainsString('| Deployment | **NOT PERFORMED** |', $section);
    }

    #[Test]
    public function atlas_documents_post_168_amendment_and_dormant_discrepancies(): void
    {
        $atlas = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('Stage 3E Magento Safe Sync read + simple trusted write consumption', $atlas);
        $this->assertStringContainsString('IMPLEMENTED (internal; support false; simple path consumed; not real-target certified)', $atlas);
        $this->assertStringContainsString('Stage 3E disposable validation harness', $atlas);
        $this->assertStringContainsString('IMPLEMENTED (internal; validation-only; support false; no real-target certification executed)', $atlas);
        $this->assertStringContainsString('trusted simple Product execution now consumes', $atlas);
        $this->assertStringContainsString('Stage 3E post-#168 dormant code-vs-docs discrepancies', $atlas);
        $this->assertStringContainsString('DOCUMENTED (dormant; not fixed)', $atlas);
        $this->assertStringContainsString('Production-unreachable code paths that still use stock SKU-addressed', $atlas);
        $this->assertStringContainsString('media (`GalleryManagement`); configurable options / child link; lifecycle status / visibility', $atlas);
        $this->assertStringContainsString('replaced before their respective Live path becomes reachable', $atlas);
    }

    #[Test]
    public function implementation_gaps_documents_post_168_amendment_summary(): void
    {
        $gaps = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('**Stage 3E Post-#168 Real-Target Certification Amendment (docs only) is recorded**', $gaps);
        $this->assertStringContainsString('9 decisions (current state, media-neutral Product save, connection quarantine,', $gaps);
        $this->assertStringContainsString('a `Code-vs-docs dormant discrepancies` table', $gaps);
        $this->assertStringContainsString('no change to the first-party `integrations/magento-safe-sync` module in this campaign', $gaps);
        $this->assertStringContainsString('Laravel trusted simple execution now consumes the previously existing Safe Sync write primitive', $gaps);
        $this->assertStringContainsString('no `composer.json` change', $gaps);
        $this->assertStringContainsString('validation-only Laravel control plane', $gaps);
        $this->assertStringContainsString('no real-target validation harness execution/certification in this PR', $gaps);
        $this->assertStringContainsString('no Live support enablement', $gaps);
        $this->assertStringContainsString('no deployment', $gaps);
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

    /**
     * @return non-empty-string
     */
    private function post168AmendmentSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/##### Stage 3E Post-#168 Real-Target Certification Amendment \(docs only\)\n\n(.*?)(?=\n#### Merchant Preview Authorization)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Stage 3E post-#168 amendment section in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }
}
