<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorIntegrationUxContractDocumentationTest extends TestCase
{
    #[Test]
    public function connector_ux_contract_is_approved_normative(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('**Status:** Approved normative connector UX contract', $content);
        $this->assertStringContainsString('**Approval date:** 2026-08-10', $content);
        $this->assertStringNotContainsString('Proposed contract, not yet approved', $content);
        $this->assertStringContainsString('workspace/merchant surface for connecting and managing external systems', $content);
        $this->assertStringContainsString('`Каталог і синхронізація` must not currently be represented as an established navigation group', $content);
    }

    #[Test]
    public function ui_design_system_documents_four_layer_connector_ux(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('## Connector Integration UX (Resolved — 2026-08-10)', $content);
        $this->assertStringContainsString('CONNECTOR_INTEGRATION_UX_CONTRACT.md', $content);
        $this->assertStringContainsString('**A — Щоденна робота**', $content);
        $this->assertStringContainsString('**B — Налаштування даних**', $content);
        $this->assertStringContainsString('**C — Діагностика**', $content);
        $this->assertStringContainsString('**D — Каталог конекторів**', $content);
        $this->assertStringContainsString('visibility ceiling, not an authorization grant', $content);
    }

    #[Test]
    public function ui_design_system_documents_integratsii_merchant_entry(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('`Інтеграції` replaces `Платформи та джерела` as the merchant connector entry point', $content);
        $this->assertStringContainsString('0, 1, or N', $content);
        $this->assertStringContainsString('CONNECTOR_INTEGRATSII_PAGE_UX_CONTRACT.md', $content);
        $this->assertStringContainsString('The `Інтеграції` landing surface exists as the merchant entry', $content);
        $this->assertStringContainsString('workspace/merchant surface for connecting and managing external systems', $content);
        $this->assertStringContainsString('It is not the merchant surface for catalog work and must not become the technical sync builder.', $content);
        $this->assertStringContainsString('Sync configuration, mapping, preview, results, and remediation belong to merchant sync/data-management surfaces', $content);
        $this->assertStringContainsString('`docs/03-DOMAIN_MODEL.md` → Sync Domain Rebaseline', $content);
        $this->assertStringContainsString('`Каталог і синхронізація` must not currently be represented as an established navigation group', $content);
        $this->assertStringContainsString('intentional interim use of standard Filament navigation behavior', $content);
    }

    #[Test]
    public function integratsii_page_ux_contract_is_approved(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATSII_PAGE_UX_CONTRACT.md'));

        $this->assertStringContainsString('**Status:** Approved page-specific contract', $content);
        $this->assertStringContainsString('platform-first, adaptive destination', $content);
        $this->assertStringContainsString('corrected worst-wins', $content);
        $this->assertStringContainsString('EligibleConnectorPlatformCatalog', $content);
        $this->assertStringContainsString('Option B', $content);
        $this->assertStringContainsString('AccountSetup', $content);
        $this->assertStringContainsString('connector_definition_code', $content);
        $this->assertStringContainsString('IntegrationsStatusVocabulary', $content);
        $this->assertStringContainsString('must **not** invent Coming Soon', $content);
    }

    #[Test]
    public function ui_design_system_documents_capability_driven_connector_surfaces(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('ConnectorCapability::supports()', $content);
        $this->assertStringContainsString('No parallel UI-only connector-capability flags', $content);
        $this->assertStringContainsString(
            'Platform-owned sync UX/orchestration concerns — including sync execution workflow, Preview, scheduling, mapping UI, issue aggregation, bulk resolution, and sync-run history — do **not** become connector capabilities merely because they are optional or not yet implemented.',
            $content,
        );
    }

    #[Test]
    public function ui_design_system_documents_forbidden_merchant_connector_vocabulary(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('`snapshot` / `знімок`', $content);
        $this->assertStringContainsString('`discovery run`', $content);
        $this->assertStringContainsString('`endpoint path`', $content);
    }

    #[Test]
    public function ui_design_system_documents_field_browser_migration_not_compliance(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('ViewConnectorSchemaSnapshot', $content);
        $this->assertStringContainsString('connectors.ui.snapshot.*', $content);
        $this->assertStringContainsString('Do not document the full connector merchant UI as contract-compliant until remaining migration work lands', $content);
        $this->assertStringContainsString('GAP-025', $content);
    }

    #[Test]
    public function ui_design_system_platfordmy_ta_dzherela_is_layer_d_only(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('**Платформи та джерела** — Layer D only', $content);
        $this->assertStringContainsString('merchants use `Інтеграції`', $content);
    }

    #[Test]
    public function domain_model_documents_connector_capability_ui_source_of_truth(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('#### ConnectorCapability as UI source of truth (Resolved — UX contract 2026-08-10)', $content);
        $this->assertStringContainsString('App\\Enums\\ConnectorCapability', $content);
        $this->assertStringContainsString('no parallel UI-only connector-capability flags', $content);
        $this->assertStringContainsString(
            'Platform-owned functionality must **not** become a connector capability merely',
            $content,
        );
        $this->assertStringContainsString('because it is optional, future, configurable, UI-driven, or not yet', $content);
        $this->assertStringContainsString('dry-run/preview orchestration', $content);
        $this->assertStringContainsString('sync-run history, and similar platform workflow/UI/orchestration capabilities', $content);
    }

    #[Test]
    public function domain_model_documents_connector_account_cardinality(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('#### ConnectorAccount cardinality (Resolved — UX contract 2026-08-10)', $content);
        $this->assertStringContainsString('zero, one, or many', $content);
        $this->assertStringContainsString('not a one-connection-per-platform model', $content);
    }

    #[Test]
    public function domain_model_documents_ownership_as_future_decision(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('#### Field/data-domain write ownership (future — UX contract 2026-08-10)', $content);
        $this->assertStringContainsString('open domain decision', $content);
        $this->assertStringContainsString('automation/scheduling off', $content);
    }

    #[Test]
    public function domain_model_documents_layer_c_not_workspace_admin(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '#### Layer C diagnostics audience (Resolved — UX contract 2026-08-10; authorization rebaselined 4C-1c-2a)',
            $content,
        );
        $this->assertStringContainsString(
            'unavailable to **all** workspace merchant role/access profiles unless',
            $content,
        );
        $this->assertStringContainsString('a separate platform-support identity exists', $content);
        $this->assertStringContainsString(
            'unavailable rather than defaulting to any workspace merchant',
            $content,
        );
    }

    #[Test]
    public function domain_model_documents_existing_vs_future_connector_ux_backend_boundary(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('dry-run/preview', $content);
        $this->assertStringContainsString('does not assert they exist', $content);
    }

    #[Test]
    public function implementation_gaps_records_connector_ux_migration_gap(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('## GAP-025 — Connector Integration UX contract not yet migrated in shipped UI', $content);
        $this->assertStringContainsString('CONNECTOR_INTEGRATION_UX_CONTRACT.md', $content);
        $this->assertStringContainsString('CONNECTOR_INTEGRATSII_PAGE_UX_CONTRACT.md', $content);
        $this->assertStringContainsString('Зведення знімка', $content);
        $this->assertStringContainsString('**Status:** Open — partial (`Інтеграції` landing shipped; SyncConfiguration,', $content);
        $this->assertStringContainsString('PR #139 for 4C-1c-2b)', $content);
        $this->assertStringNotContainsString('Layer B mapping UI still missing', $content);
    }

    #[Test]
    public function ux_contract_does_not_claim_existing_authorization_plus_ui_wiring_is_sufficient(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringNotContainsString(
            'Every rule below is satisfiable with what already exists',
            $content,
        );
        $this->assertStringContainsString(
            'Task **4C-1c-2b** Layer-B Mapping UI and its Mapping-side Available Fields supporting path are shipped',
            $content,
        );
        $this->assertStringContainsString(
            'Still absent mechanisms explicitly remain future',
            $content,
        );
        $this->assertStringContainsString(
            'historical pre-B-2 fixed `User.role` authorization satisfies this UX contract',
            $content,
        );
        $this->assertStringNotContainsString(
            'Do **not** claim that current fixed `User.role` authorization already satisfies this UX contract',
            $content,
        );
    }

    #[Test]
    public function ux_contract_field_browser_splits_persistence_from_authorization_gating(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('**snapshot persistence**', $content);
        $this->assertStringContainsString('**field query/read-model/presenter architecture**', $content);
        $this->assertStringContainsString('migrated consistently with **GAP-025** and **GAP-026**', $content);
        $this->assertStringContainsString(
            'Do **not** interpret this as "security retained entirely" or "no backend rework required" for authorization/gating',
            $content,
        );
        $this->assertStringNotContainsString(
            'is retained entirely — no backend rework required by this contract',
            $content,
        );
    }

    #[Test]
    public function ux_contract_relationship_section_rebaselines_authorization_as_historical(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringNotContainsString(
            'Does not change anything about what is already shipped (`ConnectorAccountResource`, Discovery runtime, the Field Browser, authorization boundaries)',
            $content,
        );
        $this->assertStringContainsString(
            'Authorization boundaries were subsequently rebaselined by **Task 4C-1c-2a**',
            $content,
        );
        $this->assertStringContainsString(
            'Historical pre-B-2:** fixed `User.role` authorization and `ConnectorAccountMerchandiserPresentation`',
            $content,
        );
        $this->assertStringNotContainsString(
            'Current fixed `User.role` authorization is transitional under **GAP-026**',
            $content,
        );
        $this->assertStringContainsString(
            'Required authorization-foundation work was therefore **not** merely navigation/labeling/gating UI work',
            $content,
        );
    }

    #[Test]
    public function ux_contract_documents_gap_026b_0_capability_based_connector_presentation(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('connector safe presentation — Resolved — GAP-026B-0', $content);
        $this->assertStringContainsString('capability-based**, never job-title-based', $content);
        $this->assertStringContainsString('Workspace RBAC authority cutover (Resolved — GAP-026B-0', $content);
    }

    #[Test]
    public function domain_model_documents_workspace_access_authorization_contract(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '### Workspace access model and authorization (Resolved — Task 4C-1c-2a, 2026-08-13)',
            $content,
        );
        $this->assertStringContainsString('`view_sync_mappings`', $content);
        $this->assertStringContainsString('Absence of a permission means deny.', $content);
        $this->assertStringContainsString('permission muting', $content);
    }
}
