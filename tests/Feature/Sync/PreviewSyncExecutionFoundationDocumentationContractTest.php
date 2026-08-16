<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreviewSyncExecutionFoundationDocumentationContractTest extends TestCase
{
    #[Test]
    public function domain_model_documents_preview_first_sync_execution_foundation_contract(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '#### Preview-first Sync Execution Foundation Contract',
            $content,
        );
        $this->assertStringContainsString('[Resolved — Task 4C-2a]', $content);
    }

    #[Test]
    public function contract_is_docs_only_and_does_not_authorize_live_mutation(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('architecture/documentation only', $section);
        $this->assertStringContainsString('**authorizes** a later', $section);
        $this->assertStringContainsString('does **not** authorize Live', $section);
        $this->assertStringContainsString('separate Stop-and-Amend', $section);
    }

    #[Test]
    public function contract_freezes_first_target_as_products_export_preview_adobe(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('`data_domain` | `products` only', $section);
        $this->assertStringContainsString('`semantic_operation` | `export` only', $section);
        $this->assertStringContainsString('Connector / profile target | Adobe PaaS', $section);
        $this->assertStringContainsString('Execution mode | `preview` only', $section);
    }

    #[Test]
    public function contract_freezes_one_sync_run_per_semantic_operation(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('##### One SyncRun = one semantic operation', $section);
        $this->assertStringContainsString('one SyncRun', $section);
        $this->assertStringContainsString('+ one semantic_operation', $section);
        $this->assertStringContainsString('one execution mode', $section);
        $this->assertStringContainsString('one configuration revision', $section);
        $this->assertStringContainsString('One run **never**', $section);
        $this->assertStringContainsString('executes both', $section);
    }

    #[Test]
    public function contract_forbids_automatic_import_plus_export_fan_out(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('no** automatic fan-out', $section);
        $this->assertStringContainsString('Import run + Export run', $section);
        $this->assertStringContainsString('requested semantic operation is explicit at admission', $section);
    }

    #[Test]
    public function contract_freezes_fixed_all_products_selection(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('selection.mode = all_products', $section);
        $this->assertStringContainsString('all `Product` records belonging to the `SyncConfiguration` workspace', $section);
        $this->assertStringContainsString('not merchant-configurable', $section);
        $this->assertStringContainsString('do **not** persist a mutable', $section);
        $this->assertStringContainsString('selection column yet', $section);
    }

    #[Test]
    public function contract_freezes_revision_v3_with_selection(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('babypark.sync-configuration-revision.v3', $section);
        $this->assertStringContainsString('"selection": {', $section);
        $this->assertStringContainsString('"mode": "all_products"', $section);
        $this->assertStringContainsString('4C-2b** must recompute existing `SyncConfiguration.configuration_revision`', $section);
    }

    #[Test]
    public function contract_freezes_run_sync_preview_as_independent_pending_permission(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('`run_sync_preview`', $section);
        $this->assertStringContainsString('independent from Connector, Mapping, Access, and Tax permissions', $section);
        $this->assertStringContainsString('no existing permission implies it', $section);
        $this->assertStringContainsString('it implies none of them', $section);
        $this->assertStringContainsString('normative **eighth** atomic workspace permission', $section);
        $this->assertStringContainsString('runtime implementation: pending 4C-2b', $section);
        $this->assertStringContainsString('exactly **seven** seeded permissions', $section);
    }

    #[Test]
    public function historical_seven_permission_statements_remain_truthful(): void
    {
        $domainModel = File::get(base_path('docs/03-DOMAIN_MODEL.md'));
        $gaps = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('none of these seven permissions', $domainModel);
        $this->assertStringContainsString('none of the seven permissions', $gaps);
        $this->assertStringContainsString('edit role\'s canonical seven-permission bundle', $domainModel);
        $this->assertStringContainsString('Seven atomic permissions (frozen minimum catalogue)', $domainModel);
    }

    #[Test]
    public function implementation_gaps_documents_eighth_permission_docs_to_code_gap(): void
    {
        $gaps = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('`run_sync_preview` normative', $gaps);
        $this->assertStringContainsString('runtime catalogue still seven permissions until 4C-2b', $gaps);
    }

    #[Test]
    public function contract_keeps_adobe_operation_support_fail_closed(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('does **not** implement', $section);
        $this->assertStringContainsString('ConnectorSyncOperationSupport', $section);
        $this->assertStringContainsString('remains **fail-closed**', $section);
        $this->assertStringContainsString('Preview planner is **not**,', $section);
        $this->assertStringContainsString('by itself, sufficient', $section);
        $this->assertStringContainsString('do **not** flip `ConnectorSyncOperationSupport(products, export)`', $section);
    }

    #[Test]
    public function contract_requires_zero_consequential_external_mutation_for_preview(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('zero consequential external mutation', $section);
        $this->assertStringContainsString('perform **no** mutating HTTP call', $section);
        $this->assertStringContainsString('create **no** `ExternalRecordLink`', $section);
    }

    #[Test]
    public function contract_freezes_immutable_configuration_snapshot_without_secrets(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('babypark.sync-run-input.v1', $section);
        $this->assertStringContainsString('configuration_snapshot', $section);
        $this->assertStringContainsString('Must contain **no**:', $section);
        $this->assertStringContainsString('credentials', $section);
        $this->assertStringContainsString('Product payload snapshot', $section);
    }

    #[Test]
    public function contract_separates_run_lifecycle_from_item_business_outcome(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('`status` | `queued` / `running` / `completed` / `failed`', $section);
        $this->assertStringContainsString('blocked/warning items may still have `run.status = completed`', $section);
        $this->assertStringContainsString('business findings are not infrastructure failure', $section);
    }

    #[Test]
    public function contract_freezes_exactly_three_preview_outcomes(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('##### Preview outcomes — exactly three', $section);
        $this->assertStringContainsString('`ready`', $section);
        $this->assertStringContainsString('`warning`', $section);
        $this->assertStringContainsString('`blocked`', $section);
        $this->assertStringContainsString('готові', $section);
        $this->assertStringContainsString('потребує уваги', $section);
        $this->assertStringContainsString('неможливо', $section);
        $this->assertStringContainsString('Do **not** add `excluded` in 4C-2a', $section);
    }

    #[Test]
    public function contract_uses_product_typed_sync_run_item_not_polymorphic_identity(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('Do **not** introduce `internal_record_type`', $section);
        $this->assertStringContainsString('`product_id`', $section);
        $this->assertStringContainsString('typed `Product` FK', $section);
    }

    #[Test]
    public function contract_documents_product_restrict_as_forward_looking_historical_protection(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('ON DELETE RESTRICT', $section);
        $this->assertStringContainsString('Product → ProductVariant` `CASCADE`', $section);
        $this->assertStringContainsString('no `SoftDeletes`', $section);
        $this->assertStringContainsString('`ProductResource` `DeleteAction`', $section);
        $this->assertStringContainsString('forward-looking', $section);
        $this->assertStringContainsString('historical protection', $section);
    }

    #[Test]
    public function contract_documents_one_active_run_admission_locking(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('at most one **active** `SyncRun` per `SyncConfiguration`', $section);
        $this->assertStringContainsString('lock SyncConfiguration', $section);
        $this->assertStringContainsString('verify no active run for this SyncConfiguration', $section);
        $this->assertStringContainsString('Do **not** hold a database lock during queued/background execution', $section);
    }

    #[Test]
    public function contract_defers_external_record_link_and_live_semantics(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('ExternalRecordLink` is not required for Preview', $section);
        $this->assertStringContainsString('4C-2a does **not** freeze Live retry', $section);
        $this->assertStringContainsString('Before Live', $section);
        $this->assertStringContainsString('Separate contract for `ExternalRecordLink`', $section);
    }

    #[Test]
    public function contract_distinguishes_persisted_preview_from_merchant_completed_sync_history(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('**not** expose them automatically', $section);
        $this->assertStringContainsString('automatically as completed synchronization history', $section);
        $this->assertStringContainsString('Preview-history merchant', $section);
        $this->assertStringContainsString('page in the first runtime slice', $section);
        $this->assertStringContainsString('PO-4 remains open', $section);
    }

    #[Test]
    public function contract_documents_pure_planner_boundary(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('FieldMapping` ≠ execution plan', $section);
        $this->assertStringContainsString('Adobe/profile-owned pure Preview planner', $section);
        $this->assertStringContainsString('Do **not** implement one shared `execute(..., dryRun=true)`', $section);
    }

    #[Test]
    public function contract_documents_deferred_operation_support_boundary_exposure_gate(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('Before first real merchant Preview', $section);
        $this->assertStringContainsString('reconcile the operation-support boundary', $section);
        $this->assertStringContainsString('Do not bypass `ConnectorSyncOperationSupport`', $section);
    }

    #[Test]
    public function implementation_gaps_documents_4c_2a_task_slice(): void
    {
        $gaps = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('**4C-2a**', $gaps);
        $this->assertStringContainsString('Preview-first Sync Execution Foundation Stop-and-Amend', $gaps);
        $this->assertStringContainsString('**4C-2b**', $gaps);
    }

    #[Test]
    public function contract_clarifies_preview_history_is_audit_evidence_not_catalogue_replay(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('immutable historical/audit evidence', $section);
        $this->assertStringContainsString('what that execution evaluated and concluded', $section);
        $this->assertStringContainsString('does **not** guarantee bit-for-bit replay', $section);
        $this->assertStringContainsString('Product input snapshots are not persisted', $section);
        $this->assertStringNotContainsString('audit/reproducibility evidence', $section);
    }

    #[Test]
    public function contract_scopes_configuration_snapshot_reproducibility_to_configuration_owned_input(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('configuration-owned semantic evidence only', $section);
        $this->assertStringContainsString('configuration-owned input for revision `R` auditable and', $section);
        $this->assertStringContainsString('reproducible', $section);
        $this->assertStringContainsString('does **not** enable bit-for-bit replay of the', $section);
        $this->assertStringContainsString('Product catalogue state', $section);
        $this->assertStringContainsString('Product catalogue membership list', $section);
        $this->assertStringContainsString('Product field-value snapshots', $section);
    }

    #[Test]
    public function contract_resolves_all_products_membership_at_execution_start_not_admission(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('**Temporal boundary (queued Preview):**', $section);
        $this->assertStringContainsString('at admission as part of `configuration_snapshot`', $section);
        $this->assertStringContainsString('are **not** admission-time snapshotted', $section);
        $this->assertStringContainsString('resolved when the run **begins', $section);
        $this->assertStringContainsString('execution**, under the fixed `all_products` predicate', $section);
        $this->assertStringContainsString('created after admission but before execution begins **may** belong', $section);
        $this->assertStringContainsString('`queued` status does **not** promise an admission-time catalogue snapshot', $section);
        $this->assertStringContainsString('**not** silently expand because new Products are created later', $section);
    }

    #[Test]
    public function contract_does_not_require_long_lived_execution_transaction(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('one coherent execution set', $section);
        $this->assertStringContainsString('without holding a long-lived DB transaction for the whole planner run', $section);
        $this->assertStringContainsString('do **not** require', $section);
        $this->assertStringContainsString('a long-lived DB transaction for the whole planner/execution pass', $section);
    }

    #[Test]
    public function contract_separates_configuration_revision_from_product_data_freshness(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('`configuration_revision` tracks configuration-owned execution state only', $section);
        $this->assertStringContainsString('selection contract', $section);
        $this->assertStringContainsString('does **not** prove Product catalogue membership or field data are unchanged', $section);
        $this->assertStringContainsString('Product data freshness is a distinct concern', $section);
        $this->assertStringContainsString('product-data readiness flag in 4C-2a', $section);
        $this->assertStringContainsString('later exposure/Live contract must define how Product changes after Preview', $section);
        $this->assertStringContainsString('re-preview requirements', $section);
    }

    #[Test]
    public function contract_documents_sync_run_item_as_evaluation_conclusion_not_product_snapshot(): void
    {
        $section = $this->previewExecutionFoundationContractSection();

        $this->assertStringContainsString('Each `SyncRunItem` is immutable historical/audit evidence', $section);
        $this->assertStringContainsString('it is **not** a persisted Product input', $section);
        $this->assertStringContainsString('does not by itself enable catalogue replay', $section);
    }

    /**
     * @return non-empty-string
     */
    private function previewExecutionFoundationContractSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/#### Preview-first Sync Execution Foundation Contract\n\[Resolved — Task 4C-2a\]\n\n(.*?)(?=\n### Canonical mapping registry role)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Preview-first Sync Execution Foundation Contract section in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }
}
