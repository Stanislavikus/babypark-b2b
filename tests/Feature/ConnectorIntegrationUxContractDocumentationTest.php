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
        $this->assertStringContainsString('merchant-safe projection', $content);
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
        $this->assertStringContainsString('Do not document current UI as contract-compliant until migrated', $content);
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

        $this->assertStringContainsString('#### Layer C diagnostics audience (Resolved — UX contract 2026-08-10)', $content);
        $this->assertStringContainsString('workspace Admin, Director, or Merchandiser merchant roles', $content);
        $this->assertStringContainsString('unavailable rather than defaulting to workspace Admin', $content);
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
        $this->assertStringContainsString('**Status:** Open — partial (`Інтеграції` landing shipped); remaining UX migration', $content);
    }
}
