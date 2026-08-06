<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorAccountDocumentationTest extends TestCase
{
    #[Test]
    public function domain_model_documents_connector_account_boundary(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('### ConnectorAccount (Resolved — Task 4B-0 Stop-and-Amend)', $content);
        $this->assertStringContainsString('workspace-owned', $content);
        $this->assertStringContainsString('encrypted:array', $content);
        $this->assertStringContainsString('does **not** contain', $content);
    }

    #[Test]
    public function domain_model_documents_connection_check_and_discovery_history(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('### ConnectorConnectionCheck (Resolved)', $content);
        $this->assertStringContainsString('### ConnectorDiscoveryRun (Resolved)', $content);
        $this->assertStringContainsString('append-only', $content);
    }

    #[Test]
    public function domain_model_separates_snapshot_and_diff(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('### ConnectorSchemaSnapshot (Resolved)', $content);
        $this->assertStringContainsString('### ConnectorSchemaDiff / ConnectorSchemaDiffItem (Resolved)', $content);
        $this->assertStringContainsString('**No** `previous_value` / `current_value`', $content);
    }

    #[Test]
    public function domain_model_documents_dual_axis_error_classification(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('### Dual-axis error classification (Resolved)', $content);
        $this->assertStringContainsString('authentication', $content);
        $this->assertStringContainsString('authorization', $content);
        $this->assertStringContainsString('user_action_required', $content);
        $this->assertStringContainsString('automatic_retry', $content);
    }

    #[Test]
    public function domain_model_keeps_sync_log_legacy(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('#### Boundary vs legacy `SyncLog`', $content);
        $this->assertStringContainsString('does not** extend or reuse `SyncLog`', $content);
    }

    #[Test]
    public function domain_model_keeps_field_mapping_in_task_4c(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('### Task 4B vs Task 4C boundary (Resolved)', $content);
        $this->assertStringContainsString('Discovery must **not** auto-create', $content);
        $this->assertStringContainsString('Task 4C', $content);
    }

    #[Test]
    public function ai_working_agreement_requires_visual_contract_before_persistence(): void
    {
        $content = File::get(base_path('docs/05-AI_WORKING_AGREEMENT.md'));

        $this->assertStringContainsString('### Visual Contract Before Persistence for Complex Operational Features', $content);
        $this->assertStringContainsString('A backend-only delivery is not acceptable', $content);
    }

    #[Test]
    public function ui_design_system_documents_connection_and_discovery_states(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('## Operational Connection Pattern (reusable)', $content);
        $this->assertStringContainsString('Потребує уваги', $content);
        $this->assertStringContainsString('401', $content);
        $this->assertStringContainsString('403', $content);
    }

    #[Test]
    public function ui_design_system_documents_current_state_vs_history(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('### Current state vs history', $content);
        $this->assertStringContainsString('current projection', $content);
        $this->assertStringContainsString('Activity history', $content);
    }

    #[Test]
    public function implementation_gaps_documents_task_4b_sequence(): void
    {
        $gap006 = $this->gap006Section();

        $this->assertStringContainsString('**4B-0**', $gap006);
        $this->assertStringContainsString('**4B-1**', $gap006);
        $this->assertStringContainsString('**4B-2-0**', $gap006);
        $this->assertStringContainsString('**4B-2a**', $gap006);
        $this->assertStringContainsString('**4B-2b**', $gap006);
        $this->assertStringContainsString('**4B-2c**', $gap006);
        $this->assertStringContainsString('**4B-2d**', $gap006);
        $this->assertStringContainsString('**4C**', $gap006);
    }

    #[Test]
    public function implementation_gaps_task_4b2a_includes_connection_operator_surfaces(): void
    {
        $gap006 = $this->gap006Section();

        $this->assertMatchesRegularExpression(
            '/\*\*4B-2a\*\*.*connection-check execution and queue lifecycle/s',
            $gap006
        );
        $this->assertMatchesRegularExpression(
            '/\*\*4B-2a\*\*.*list\/detail\/history admin UI and current projection/s',
            $gap006
        );
        $this->assertMatchesRegularExpression(
            '/\*\*4B-2a\*\*.*Done, PRs #87, #89–#94/s',
            $gap006
        );
    }

    #[Test]
    public function implementation_gaps_task_4b2b_includes_discovery_overview_ui(): void
    {
        $gap006 = $this->gap006Section();

        $this->assertMatchesRegularExpression(
            '/\*\*4B-2b\*\*.*Discovery Overview UI/s',
            $gap006
        );
    }

    #[Test]
    public function implementation_gaps_records_task_4b1_as_done(): void
    {
        $gap006 = $this->gap006Section();

        $this->assertStringContainsString('**4B-1** | Generic `ConnectorAccount` persistence/domain foundation — Done, PR #85', $gap006);
        $this->assertStringContainsString('**Task 4B-1 note (added 2026-07-22):**', $gap006);
    }

    #[Test]
    public function implementation_gaps_gap_006_no_longer_blocked_on_gap_016(): void
    {
        $gap006 = $this->gap006Section();

        $this->assertStringNotContainsString('blocked on GAP-016', $gap006);
        $this->assertStringNotContainsString('GAP-016 and GAP-017 are Closed in code', $gap006);
        $this->assertStringContainsString('**Status:** Open. Task 4B-2a is complete.', $gap006);
    }

    #[Test]
    public function implementation_gaps_gap_006_does_not_claim_connector_account_migrations_absent(): void
    {
        $gap006 = $this->gap006Section();

        $this->assertStringNotContainsString('No `ConnectorAccount`', $gap006);
        $this->assertStringNotContainsString('models or migrations yet', $gap006);
        $this->assertStringContainsString('Task 4B-1 / PR #85 added `ConnectorAccount`', $gap006);
    }

    #[Test]
    public function implementation_gaps_gap_006_does_not_wait_for_field_foundation(): void
    {
        $gap006 = $this->gap006Section();

        $this->assertStringNotContainsString('Do not resume Connector Foundation work until', $gap006);
        $this->assertStringNotContainsString('wait for Field Foundation', $gap006);
    }

    #[Test]
    public function project_documentation_map_uses_22_item_checklist(): void
    {
        $content = File::get(base_path('docs/Project_Documentation_Map.md'));

        $this->assertStringNotContainsString('20-item', $content);
        $this->assertStringContainsString('22-item', $content);
        $this->assertStringContainsString('external URL / SSRF safety', $content);
        $this->assertStringContainsString('connector secret handling', $content);
    }

    #[Test]
    public function domain_model_connector_scope_is_resolved_with_adobe_paas_first(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('### Connector scope (Resolved)', $content);
        $this->assertStringNotContainsString('The MVP should define which connector comes first', $content);
        $this->assertStringContainsString('adobe_commerce_paas_oauth1_integration', $content);
        $this->assertStringContainsString('Adobe Commerce PaaS/on-prem', $content);
        $this->assertStringContainsString('**Decision authority:** project-owner approval dated 2026-07-22', $content);
    }

    #[Test]
    public function project_documentation_map_connector_scope_points_to_resolved_domain_section(): void
    {
        $content = File::get(base_path('docs/Project_Documentation_Map.md'));

        $this->assertSame(
            0,
            substr_count($content, 'Connector scope for MVP (which connector comes first)'),
            'Stale unresolved connector-scope phrasing must not appear anywhere in Project_Documentation_Map.md'
        );

        $openDecisionsBullets = $this->projectMapOpenDecisionsBulletListSection();

        $this->assertStringNotContainsString(
            'Connector scope for MVP (which connector comes first)',
            $openDecisionsBullets,
            'Open-decisions bullet list must not list connector scope as unresolved'
        );
        $this->assertStringNotContainsString(
            'Connector scope for MVP',
            $openDecisionsBullets,
            'Resolved connector scope must not remain in the open-decisions bullet list'
        );

        $openDecisionsTable = $this->projectMapOpenDecisionsTableSection();

        $this->assertMatchesRegularExpression(
            '/\| Connector scope for MVP \| 03 \| \*\*Resolved\*\* — Adobe Commerce PaaS\/on-prem first \(`03-DOMAIN_MODEL\.md`, Connector scope \(Resolved\)\) \|/',
            $openDecisionsTable,
            'Open-decisions table must point connector scope to the Resolved section in 03-DOMAIN_MODEL.md'
        );
    }

    /**
     * @return non-empty-string
     */
    private function projectMapOpenDecisionsBulletListSection(): string
    {
        $content = File::get(base_path('docs/Project_Documentation_Map.md'));

        if (! preg_match(
            '/\*\*Open decisions still requiring resolution before implementation:\*\*\s*\n\n(.*?)(?=\n\nThis file guides database design)/s',
            $content,
            $matches
        )) {
            $this->fail('Could not locate open-decisions bullet list in Project_Documentation_Map.md');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function projectMapOpenDecisionsTableSection(): string
    {
        $content = File::get(base_path('docs/Project_Documentation_Map.md'));

        if (! preg_match(
            '/## Open Decisions Requiring Resolution Before Implementation\n\n.*?\n\n(\| Decision \| Relevant Files \| Status \|\n\|---\|---\|---\|\n(?:\|.*\|\n)+)/s',
            $content,
            $matches
        )) {
            $this->fail('Could not locate open-decisions table in Project_Documentation_Map.md');
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

    #[Test]
    public function implementation_gaps_keeps_gap_006_open(): void
    {
        $gap006 = $this->gap006Section();

        $this->assertStringContainsString('GAP-006 stays Open', File::get(base_path('docs/IMPLEMENTATION_GAPS.md')));
        $this->assertStringContainsString('**Status:** Open. Task 4B-2a is complete.', $gap006);
        $this->assertStringNotContainsString('**Status:** Open. Unblocked', $gap006);
        $this->assertStringNotContainsString(
            'Runtime connection/discovery behavior and FieldMapping remain unimplemented',
            $gap006
        );
    }

    #[Test]
    public function ui_design_system_documents_discovery_overview_snapshot_link_scope(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString(
            'link to current snapshot',
            $content
        );
        $this->assertStringContainsString(
            'minimal read-only',
            $content
        );
        $this->assertStringContainsString(
            'snapshot detail page',
            $content
        );
        $this->assertStringContainsString(
            'first-snapshot / no-change label only',
            $content
        );
        $this->assertStringContainsString(
            'no canonical hash',
            $content
        );
        $this->assertStringContainsString(
            'Task 4B-2c extends that same page',
            $content
        );
    }

    #[Test]
    public function implementation_gaps_records_task_4b2b0_runtime_prerequisite(): void
    {
        $gap006 = $this->gap006Section();

        $this->assertStringContainsString('**Task 4B-2b-0 note (added 2026-07-29):**', $gap006);
        $this->assertStringContainsString('database_connectors', $gap006);
        $this->assertMatchesRegularExpression(
            '/Prerequisite for\s+Task 4B-2b-1 discovery execution/',
            $gap006
        );
    }

    #[Test]
    public function prototype_desktop_and_mobile_screenshots_are_distinct(): void
    {
        $dir = base_path('docs/prototypes/task-4b0-connector-account/screenshots');
        $pairs = [
            ['02-settings-desktop-1440.png', '02-settings-mobile-375.png'],
            ['03-check-error-auth-desktop-1440.png', '03-check-error-auth-mobile-375.png'],
            ['04-discovery-desktop-1440.png', '04-discovery-mobile-375.png'],
            ['06-history-desktop-1440.png', '06-history-mobile-375.png'],
        ];

        foreach ($pairs as [$desktop, $mobile]) {
            $desktopPath = $dir.'/'.$desktop;
            $mobilePath = $dir.'/'.$mobile;

            $this->assertFileExists($desktopPath, "Missing desktop screenshot: {$desktop}");
            $this->assertFileExists($mobilePath, "Missing mobile screenshot: {$mobile}");

            $desktopHash = hash_file('sha256', $desktopPath);
            $mobileHash = hash_file('sha256', $mobilePath);

            $this->assertNotSame(
                $desktopHash,
                $mobileHash,
                "{$desktop} and {$mobile} are byte-identical (not a real distinct capture)"
            );
        }
    }

    #[Test]
    public function connector_accounts_schema_is_resolved_not_candidate(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('#### Physical schema — `connector_accounts` (Resolved)', $content);
        $this->assertStringNotContainsString('(candidate)', $content);
        $this->assertStringContainsString('active_name_uniqueness_key', $content);
        $this->assertStringContainsString('**Uniqueness (Resolved):**', $content);
    }

    #[Test]
    public function domain_model_documents_full_physical_schemas_for_diff_tables(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('#### Physical schema — `connector_schema_diffs` (Resolved)', $content);
        $this->assertStringContainsString('#### Physical schema — `connector_schema_diff_items` (Resolved)', $content);
        $this->assertStringContainsString('| `is_first_snapshot` | boolean |', $content);
        $this->assertStringContainsString('| `change_type` | enum | `added`, `removed`, `changed` |', $content);
        $this->assertStringContainsString('| `before_snapshot_field_id` | UUID FK nullable |', $content);
        $this->assertStringContainsString('| `after_snapshot_field_id` | UUID FK nullable |', $content);
    }

    #[Test]
    public function retention_pruning_order_names_connector_schema_diffs(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            'Pruning order: `connector_schema_diff_items` → `connector_schema_diffs` →',
            $content
        );
        $this->assertStringContainsString('eligible `connector_discovery_runs` → old `connector_connection_checks`', $content);
    }

    #[Test]
    public function fk_matrix_documents_restrict_on_delete_for_all_fourteen_composite_edges(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('### FK delete-behavior matrix (Resolved)', $content);

        $explicitCompositeRows = [
            'connector_discovery_runs.snapshot_id',
            'connector_discovery_runs.previous_snapshot_id',
            'connector_schema_snapshots.previous_snapshot_id',
            'connector_schema_snapshots.discovery_run_id',
            'connector_schema_snapshot_fields.snapshot_id',
        ];

        foreach ($explicitCompositeRows as $edge) {
            $this->assertMatchesRegularExpression(
                '/\| `'.preg_quote($edge, '/').'` \| `restrictOnDelete\(\)` \(composite\)/',
                $content,
                "Missing restrictOnDelete() (composite) for {$edge}"
            );
        }

        $this->assertStringContainsString(
            '| `connector_schema_diffs.from_snapshot_id` / `.to_snapshot_id` | `restrictOnDelete()` (composite) |',
            $content
        );
        $this->assertStringContainsString(
            '| `connector_schema_diff_items.before_snapshot_field_id` / `.after_snapshot_field_id` | `restrictOnDelete()` (composite) |',
            $content
        );
        $this->assertStringContainsString(
            '| All `connector_account_id` / `connector_schema_source_id` / `connector_definition_id` references | `restrictOnDelete()` |',
            $content
        );
        $this->assertStringContainsString(
            '| `connector_schema_diff_id` | UUID FK | Composite guard with `workspace_id` |',
            $content
        );
    }

    #[Test]
    public function fk_matrix_allows_null_on_delete_for_initiated_by_user_id_only(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '| `initiated_by_user_id` (checks, runs) | `nullOnDelete()` |',
            $content
        );
    }

    #[Test]
    public function initiated_by_user_id_is_unsigned_bigint_in_checks_and_runs(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $occurrences = substr_count(
            $content,
            '| `initiated_by_user_id` | unsigned bigint FK nullable | Null for scheduled; matches `users.id` (bigint, not UUID) |'
        );

        $this->assertSame(2, $occurrences);

        $this->assertStringContainsString(
            '| `initiated_by_user_id` | unsigned bigint FK nullable |',
            $content
        );
        $this->assertStringNotContainsString(
            '| `initiated_by_user_id` | UUID FK nullable |',
            $content
        );
    }

    #[Test]
    public function discovery_run_started_and_finished_at_are_separate_nullable_rows(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('| `started_at` | timestamp nullable | Null while `status: queued` |', $content);
        $this->assertStringContainsString(
            '| `finished_at` | timestamp nullable | Set only on terminal state (`succeeded`/`failed`/`cancelled`) |',
            $content
        );
        $this->assertStringNotContainsString('| `started_at` / `finished_at` | timestamps | |', $content);
    }

    #[Test]
    public function retention_documents_producing_run_exception_and_history_indexes(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            'retained for at least as long as any snapshot that references it',
            $content
        );
        $this->assertStringContainsString(
            '- `connector_connection_checks`: `(connector_account_id, created_at)`',
            $content
        );
        $this->assertStringContainsString(
            '- `connector_discovery_runs`: `(connector_account_id, created_at)`',
            $content
        );
        $this->assertStringContainsString(
            '- `connector_schema_snapshots`: `(connector_account_id, connector_schema_source_id, created_at)`',
            $content
        );
    }

    #[Test]
    public function cross_reference_consistency_invariants_are_documented_for_task_4b2(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '### Cross-reference consistency invariants (documented now, enforced in Task 4B-2)',
            $content
        );
        $this->assertStringContainsString(
            'are **not** implemented by Task 4B-1',
            $content
        );
        $this->assertStringContainsString(
            'connector_schema_source.connector_definition_id` must equal the related',
            $content
        );
        $this->assertStringContainsString(
            'connector_schema_snapshots.discovery_run_id` must equal that run\'s own `id`',
            $content
        );
        $this->assertStringContainsString(
            'both referenced snapshots must belong to the same',
            $content
        );
        $this->assertStringContainsString(
            'before_snapshot_field_id` must belong to the',
            $content
        );
        $this->assertStringContainsString(
            'after_snapshot_field_id` must belong to the',
            $content
        );
    }

    #[Test]
    public function driver_scope_is_mysql_and_sqlite_only(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('Supported and tested in this task: MySQL, SQLite.', $content);
        $this->assertStringContainsString('MySQL:  `VARCHAR(255) AS (...) VIRTUAL`', $content);
        $this->assertStringContainsString('SQLite: `TEXT GENERATED ALWAYS AS (...) VIRTUAL`', $content);
        $this->assertStringContainsString(
            'Task 4B-1 does not introduce or claim a PostgreSQL migration contract',
            $content
        );
        $this->assertStringNotContainsString('verified PostgreSQL support', $content);
    }

    #[Test]
    public function domain_model_connector_adapter_sections_are_resolved_not_proposed(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('### Connector adapter capabilities (Resolved)', $content);
        $this->assertStringContainsString('#### Credential and settings classification (Resolved)', $content);
        $this->assertStringNotContainsString('Connector adapter capabilities (proposed)', $content);
        $this->assertStringNotContainsString('Credential and settings classification (proposed)', $content);
    }

    #[Test]
    public function promoted_task_4b2_0_runtime_decisions_exist_in_core_docs(): void
    {
        $domainModel = File::get(base_path('docs/03-DOMAIN_MODEL.md'));
        $architecture = File::get(base_path('docs/04-ARCHITECTURE_PRINCIPLES.md'));
        $aiAgreement = File::get(base_path('docs/05-AI_WORKING_AGREEMENT.md'));
        $uiDesign = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));
        $techStack = File::get(base_path('docs/07-TECH_STACK.md'));
        $gaps = $this->gap006Section();

        $this->assertStringContainsString('### Connector adapter capabilities (Resolved)', $domainModel);
        $this->assertStringContainsString('#### Credential and settings classification (Resolved)', $domainModel);
        $this->assertMatchesRegularExpression(
            '/reusing `store_code` for the `Store`\s+header value is the preferred convention pending approval/',
            $domainModel
        );
        $this->assertStringContainsString('### ConnectorAccount authorization (Resolved)', $domainModel);
        $this->assertStringContainsString('Merchandiser may run **manual** discovery', $domainModel);
        $this->assertStringContainsString('### Connection-check capability and error mapping (Resolved)', $domainModel);
        $this->assertStringContainsString('### Connection-check enqueue state (Resolved)', $domainModel);
        $this->assertStringContainsString('`ConnectorConnectionCheckStatus` includes `Queued`', $domainModel);

        $this->assertStringContainsString('**Capability-gated adapters:**', $architecture);
        $this->assertStringContainsString('**Account-level execution lock:**', $architecture);

        $this->assertStringContainsString('### Connector runtime Stop-and-Amend gate', $aiAgreement);
        $this->assertStringContainsString('### Connector implementation test baseline (Resolved)', $aiAgreement);
        $this->assertStringContainsString(
            'approved B15 test matrix in `docs/proposals/task-4b2-0-runtime-decisions.md`',
            $aiAgreement
        );

        $this->assertStringContainsString('#### Connector runtime polling (Resolved)', $uiDesign);

        $connectorRuntime = $this->techStackConnectorRuntimeSection($techStack);
        $this->assertStringContainsString('### Connector profile registry', $connectorRuntime);
        $this->assertStringContainsString('### Adobe PaaS OAuth 1.0a signing (Resolved)', $connectorRuntime);
        $this->assertStringContainsString('api-clients/psr7-oauth1', $connectorRuntime);
        $this->assertStringContainsString('psr/http-message ^1.0.1', $connectorRuntime);
        $this->assertMatchesRegularExpression(
            '/it must not be\s+promoted here as a pre-approved dependency/',
            $connectorRuntime
        );
        $this->assertStringContainsString('### Connector queue workers (production)', $connectorRuntime);
        $this->assertStringContainsString('### Connector idempotency and overlap locking (Resolved)', $connectorRuntime);
        $this->assertStringContainsString('MVP connector jobs do **not** implement `ShouldBeUnique`', $connectorRuntime);
        $this->assertStringContainsString('Dispatch failure compensation', $connectorRuntime);
        $this->assertStringContainsString('### Connector timeout and retry policy (Resolved)', $connectorRuntime);
        $this->assertStringContainsString('### Queue timeout alignment (Resolved)', $connectorRuntime);
        $this->assertStringContainsString('`pcntl` PHP extension', $connectorRuntime);
        $this->assertStringContainsString('database_connectors', $connectorRuntime);
        $this->assertStringContainsString('Connection check and discovery use the same shared account-level lock key', $connectorRuntime);
        $this->assertMatchesRegularExpression(
            '/its relationship\s+to `retry_after` is lane-specific and must follow the Queue timeout alignment\s+table below/',
            $connectorRuntime
        );
        $this->assertDoesNotMatchRegularExpression(
            "/bounded TTL above each job's timeout and its lane's\s+`retry_after`/",
            $connectorRuntime
        );
        $this->assertStringContainsString('1100 seconds for the future 900-second discovery job', $connectorRuntime);
        $this->assertStringContainsString('`deploy.sh` runs `php artisan queue:restart`', $connectorRuntime);
        $this->assertStringContainsString('### SSRF-safe connector outbound transport', $connectorRuntime);
        $this->assertStringContainsString('CURLOPT_RESOLVE', $connectorRuntime);
        $this->assertStringContainsString('### Connector secret lifecycle (Resolved)', $connectorRuntime);
        $this->assertStringContainsString('APP_PREVIOUS_KEYS', $connectorRuntime);

        $this->assertStringContainsString('**Task 4B-2-0 note (added 2026-07-22):**', $gaps);
        $this->assertStringContainsString('SaaS `Store`-header vs `store_code` reuse (B3)', $gaps);
        $this->assertStringContainsString('The B9 repository implementation and host-prerequisite verification are', $gaps);
        $this->assertStringContainsString('complete: this PR adds `php artisan queue:restart` to `deploy.sh`', $gaps);
        $this->assertStringContainsString('babypark-connector-queue` remains intentionally uninstalled and is deferred until Task 4B-2b-1', $gaps);
        $this->assertStringContainsString('**GAP-024**', $gaps);
        $this->assertStringContainsString('does **not** close GAP-024', $gaps);
    }

    #[Test]
    public function promoted_b7_connection_check_table_has_single_404_row(): void
    {
        $section = $this->domainModelConnectionCheckMappingSection();

        $this->assertSame(
            1,
            substr_count($section, '| 404 |'),
            'B7 table must contain exactly one 404 row'
        );
        $this->assertStringContainsString(
            'connectors.errors.invalid_or_unsupported_endpoint',
            $section
        );
        $this->assertStringNotContainsString('connectors.errors.unsupported_endpoint', $section);
    }

    #[Test]
    public function b15_test_matrix_is_not_duplicated_into_core_docs(): void
    {
        $coreDocs = [
            File::get(base_path('docs/03-DOMAIN_MODEL.md')),
            File::get(base_path('docs/04-ARCHITECTURE_PRINCIPLES.md')),
            File::get(base_path('docs/05-AI_WORKING_AGREEMENT.md')),
            File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md')),
            File::get(base_path('docs/07-TECH_STACK.md')),
        ];

        foreach ($coreDocs as $content) {
            $this->assertStringNotContainsString('## B15 — Future implementation test contract', $content);
            $this->assertStringNotContainsString('| Adapter registry | unknown/disabled profile |', $content);
        }

        $aiAgreement = File::get(base_path('docs/05-AI_WORKING_AGREEMENT.md'));
        $this->assertStringContainsString('approved B15 test matrix', $aiAgreement);
    }

    #[Test]
    public function proposal_file_marks_decisions_reviewed_with_fifteen_checked_boxes(): void
    {
        $proposal = File::get(base_path('docs/proposals/task-4b2-0-runtime-decisions.md'));

        $this->assertStringContainsString(
            'Reviewed; approved decisions promoted to core documents',
            $proposal
        );
        $this->assertStringContainsString(
            'Normative authority:** core documents only',
            $proposal
        );

        $checklist = $this->proposalApprovalChecklistSection($proposal);
        $this->assertSame(15, substr_count($checklist, '- [x] B'));
        $this->assertSame(0, substr_count($checklist, '- [ ] B'));
    }

    #[Test]
    public function domain_model_documents_connector_schema_canonical_hashing_contract(): void
    {
        $section = $this->connectorSchemaCanonicalHashingSection();

        $this->assertStringContainsString('babypark.connector-schema-field.v1', $section);
        $this->assertStringContainsString('babypark.connector-schema-snapshot.v1', $section);
        $this->assertStringContainsString('0x0A', $section);
        $this->assertStringContainsString('no carriage return, and no', $section);
        $this->assertStringContainsString('trailing newline after the JSON document', $section);
        $this->assertStringContainsString('JSON_UNESCAPED_UNICODE', $section);
        $this->assertStringContainsString('JSON_UNESCAPED_SLASHES', $section);
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $section);
        $this->assertStringContainsString('`JSON_FORCE_OBJECT` is forbidden', $section);
        $this->assertStringContainsString('`JSON_PRETTY_PRINT`', $section);
        $this->assertStringContainsString('`JSON_NUMERIC_CHECK`', $section);
        $this->assertStringContainsString('`JSON_INVALID_UTF8_IGNORE`', $section);
        $this->assertStringContainsString('`JSON_INVALID_UTF8_SUBSTITUTE`', $section);
        $this->assertStringContainsString('`JSON_PARTIAL_OUTPUT_ON_ERROR`', $section);
        $this->assertStringContainsString('SHA-256', $section);
        $this->assertStringContainsString('`char(64)`', $section);

        foreach ([
            'external_field_key',
            'external_label',
            'normalized_data_type',
            'is_required',
            'is_multi_value',
            'is_localizable',
            'external_scope',
            'normalized_payload',
            'sort_order',
        ] as $fieldName) {
            $this->assertStringContainsString('`'.$fieldName.'`', $section);
        }

        $this->assertStringContainsString('`external_field_key`: UTF-8 string;', $section);
        $this->assertStringContainsString('`external_label`: UTF-8 string or `null`;', $section);
        $this->assertStringContainsString('`normalized_data_type`: UTF-8 string;', $section);
        $this->assertStringContainsString('`is_required`: boolean or `null`;', $section);
        $this->assertStringContainsString('`is_multi_value`: boolean or `null`;', $section);
        $this->assertStringContainsString('`is_localizable`: boolean or `null`;', $section);
        $this->assertStringContainsString('`external_scope`: UTF-8 string or `null`;', $section);
        $this->assertStringContainsString('`normalized_payload`: JSON object', $section);
        $this->assertStringContainsString('`sort_order`: non-negative integer or `null`.', $section);

        $this->assertStringContainsString('Boolean fields must be encoded as JSON `true`/`false`/`null`, never as', $section);
        $this->assertStringContainsString('`0`, `1`, `"0"`, or `"1"`', $section);
        $this->assertStringContainsString('`null` and an empty', $section);
        $this->assertStringContainsString('string are distinct canonical values', $section);
        $this->assertStringContainsString('String fields must never be converted to', $section);
        $this->assertStringContainsString('numbers merely because their contents are numeric', $section);

        $this->assertStringContainsString('`value`: non-null UTF-8 string;', $section);
        $this->assertStringContainsString('`label`: UTF-8 string or `null`', $section);
        $this->assertStringContainsString('Duplicate values fail with `schema_validation`', $section);

        $this->assertStringContainsString('page number, item offset,', $section);
        $this->assertStringContainsString('response-array position, database insertion order', $section);
        $this->assertStringContainsString('or the order in which', $section);
        $this->assertStringContainsString('pages completed', $section);

        $this->assertStringContainsString('`normalized_payload` is always a JSON object. When it has no keys, its', $section);
        $this->assertStringContainsString('canonical encoding is `{}`, never `[]`', $section);
        $this->assertStringContainsString('`options` is always a JSON list. When it has no items, its canonical', $section);
        $this->assertStringContainsString('encoding is `[]`, never `{}`', $section);

        $this->assertStringContainsString('{"fields":[{"canonical_hash":"...","external_field_key":"..."}]}', $section);

        $this->assertStringContainsString('not full RFC 8785 (JSON', $section);
        $this->assertStringContainsString('the algorithm must never change silently', $section);
        $this->assertStringContainsString('requires an explicit documentation-level decision and a rebaseline plan', $section);
    }

    #[Test]
    public function domain_model_documents_discovery_runtime_stop_and_amend_contract(): void
    {
        $section = $this->connectorDiscoveryRunRuntimeSection();

        $this->assertStringContainsString('Maximum vendor-execution attempts: 3 total (initial + 2 retries).', $section);
        $this->assertStringContainsString('Base retry delays: 60s before the first retry, 300s before the second.', $section);
        $this->assertStringContainsString('`retry_until_at` = dispatch time + 60 minutes.', $section);
        $this->assertStringContainsString('HTTP-client-level automatic retries: 0', $section);

        $this->assertStringContainsString('`connector_definition_id` = the account\'s own `connector_definition_id`;', $section);
        $this->assertStringContainsString('`schema_scope` = `Account`;', $section);
        $this->assertStringContainsString('`source_kind` = `AccountApi`;', $section);
        $this->assertStringContainsString('`acquisition_mode` = `LiveFetch`;', $section);
        $this->assertStringContainsString('`is_primary` = `true`;', $section);
        $this->assertStringContainsString('`endpoint_path` is a non-null, non-empty **relative** API path', $section);
        $this->assertStringContainsString('all six conditions above', $section);
        $this->assertStringContainsString('re-verifies, before any HTTP', $section);
        $this->assertStringContainsString('pre-dispatch configuration failure — no', $section);

        $this->assertStringContainsString('`searchCriteria[pageSize]=200` explicitly', $section);
        $this->assertStringContainsString('if `total_count > 10,000`, the run fails before any further page is', $section);
        $this->assertStringContainsString('a 51st page request is never issued', $section);
        $this->assertStringContainsString('**Pagination-error precedence (Resolved)**', $section);
        $this->assertStringContainsString('→ `DiscoveryPaginationLimitExceeded`, checked first;', $section);
        $this->assertStringContainsString('`DiscoveryIncompletePagination`;', $section);

        $this->assertStringContainsString('lifecycle and pre-execution', $section);
        $this->assertStringContainsString(
            'control failures that do not represent a classified vendor/HTTP/schema',
            $section
        );
        $this->assertStringNotContainsString('queue/infrastructure only', $section);

        $this->assertStringContainsString('`discovery_dispatch_failed`', $section);
        $this->assertStringContainsString('`discovery_job_failed`', $section);
        $this->assertStringContainsString('`discovery_attempts_exhausted_without_result`', $section);
        $this->assertStringContainsString('`discovery_account_disabled_before_execution`', $section);
        $this->assertStringContainsString('`discovery_source_invalid_before_execution`', $section);

        $this->assertStringContainsString('`transport_response_size_exceeded`', $section);
        $this->assertStringContainsString("'discovery_pagination_limit_exceeded'", $section);
        $this->assertStringContainsString("'discovery_incomplete_pagination'", $section);
        $this->assertStringContainsString("'discovery_schema_validation_failed'", $section);

        $this->assertStringContainsString('is a **superset** of the', $section);
        $this->assertStringContainsString('**Shared `automatic_retry` result codes (verbatim reuse):**', $section);

        foreach ([
            ['AdobeRequestTimeout', 'adobe_request_timeout'],
            ['AdobeRateLimited', 'adobe_rate_limited'],
            ['AdobeVendorUnavailable', 'adobe_vendor_unavailable'],
            ['TransportDnsResolutionFailed', 'transport_dns_resolution_failed'],
            ['TransportTimeout', 'transport_timeout'],
            ['TransportConnectionFailed', 'transport_connection_failed'],
        ] as [$case, $value]) {
            $this->assertStringContainsString("| `{$case}` | `{$value}` |", $section);
            $this->assertMatchesRegularExpression(
                "/\| `{$case}` \| `{$value}` \| [^|]+ \| `automatic_retry` \|/s",
                $section,
                "Expected {$case} to be documented as automatic_retry in the discovery result table",
            );
        }

        $this->assertStringContainsString(
            'does **not** define a separate gateway-specific code',
            $section
        );

        $this->assertStringContainsString(
            '**`discovery_attempts_exhausted_without_result` never overwrites the',
            $section
        );
        $this->assertStringContainsString('itself never changes `connection_status`**', $section);
        $this->assertStringContainsString('actionability is `automatic_retry`', $section);
        $this->assertStringContainsString('`connection_status = TemporarilyUnavailable`', $section);
        $this->assertStringContainsString('only if this run is the newest run for', $section);

        $this->assertStringContainsString('application-level invariant', $section);
        $this->assertStringContainsString('does not enforce uniqueness by itself', $section);

        $techStack = File::get(base_path('docs/07-TECH_STACK.md'));
        $this->assertStringContainsString(
            'proposed operational values, not a pre-existing external standard',
            $techStack
        );
        $this->assertStringContainsString('**Discovery worker activation gate:**', $techStack);

        $gap006 = $this->gap006Section();
        $this->assertStringContainsString('ConnectorAccount authorization/rendered-view sub-gap (closed', $gap006);
        $this->assertStringContainsString('ConnectorAccountPolicy', $gap006);
    }

    #[Test]
    public function domain_model_documents_adobe_attribute_normalization_contract(): void
    {
        $section = $this->adobeAttributeNormalizationSection();

        $this->assertStringContainsString('### Adobe attribute normalization (Resolved)', $section);
        $this->assertStringContainsString('list endpoint only, no per-attribute enrichment', $section);
        $this->assertStringContainsString('do not add N+1 per-attribute detail requests in v1', $section);

        $mappingRows = [
            ['external_field_key', '`attribute_code`', 'direct copy, no transformation'],
            ['external_label', '`default_frontend_label`', 'direct copy. `frontend_labels[]`'],
            ['normalized_data_type', '`frontend_input`', '`text`→`text`'],
            ['is_required', '`is_required`', 'never defaulted to `false`'],
            ['is_multi_value', 'derived', '`true` when `frontend_input` is `multiselect` or `gallery`'],
            ['is_localizable', 'derived from `scope`', '`global`→`false`, `website`→`false`, `store`→`true`'],
            ['external_scope', '`scope` (the REST-visible string field on the attribute object)', 'normalized to the closed lowercase vocabulary `global`/`website`/`store`'],
            ['normalized_payload', 'whitelist, closed for v1', 'producing `{"options":[...]}`'],
            ['sort_order', '`position`', 'only `position` is read'],
        ];

        foreach ($mappingRows as [$canonical, $source, $ruleFragment]) {
            $this->assertStringContainsString("| `{$canonical}` | {$source} |", $section);
            $this->assertStringContainsString($ruleFragment, $section);
        }

        foreach ([
            'text' => 'text',
            'textarea' => 'long_text',
            'texteditor' => 'long_text',
            'date' => 'date',
            'datetime' => 'datetime',
            'boolean' => 'boolean',
            'select' => 'select',
            'multiselect' => 'multi_select',
            'price' => 'money',
            'media_image' => 'image',
            'gallery' => 'image_collection',
            'weight' => 'number',
        ] as $adobeInput => $canonicalType) {
            $this->assertStringContainsString(
                "`{$adobeInput}`→`{$canonicalType}`",
                $section,
                "Missing closed lookup mapping for frontend_input {$adobeInput}"
            );
        }

        $this->assertStringContainsString(
            '| `is_multi_value` | derived | `true` when `frontend_input` is `multiselect` or `gallery`',
            $section
        );
        $this->assertStringContainsString(
            '| `is_localizable` | derived from `scope` | `global`→`false`, `website`→`false`, `store`→`true`',
            $section
        );
        $this->assertStringContainsString(
            'Any `frontend_input` value not in this table terminates the whole vendor execution attempt with `DiscoverySchemaValidationFailed`',
            $section
        );
        $this->assertStringContainsString(
            'any other value terminates the whole vendor execution attempt with `DiscoverySchemaValidationFailed`',
            $section
        );
        $this->assertStringContainsString(
            'for all other `normalized_data_type` values, `normalized_payload` is always `{}`',
            $section
        );
        $this->assertStringContainsString(
            'producing `{"options":[...]}` (empty list allowed: `{"options":[]}`)',
            $section
        );
        $this->assertStringContainsString(
            '`options` missing or `null` → terminates',
            $section
        );
        $this->assertStringContainsString(
            'the whole vendor execution attempt with `DiscoverySchemaValidationFailed`',
            $section
        );
        $this->assertStringContainsString(
            '`options` present as an empty list `[]` → valid, produces',
            $section
        );
        $this->assertStringContainsString(
            '`normalized_payload: {"options":[]}`',
            $section
        );
        $this->assertStringContainsString(
            'including a numeric string like `"10"`, since the canonical contract forbids coercing a numeric string into a number',
            $section
        );
        $this->assertStringContainsString(
            'A vendor extension field literally named `sort_order`, if one happens to be present, is not used in v1 — only `position` is read',
            $section
        );
        $this->assertStringContainsString(
            'unknown extension/module fields from any installed Magento module',
            $section
        );
        $this->assertStringContainsString(
            'is silently',
            $section
        );
        $this->assertStringContainsString(
            'ignored and never persisted',
            $section
        );
        $this->assertStringContainsString('#### Whole-attempt schema-validation semantics (v1)', $section);
        $this->assertStringContainsString(
            '**invalidates the complete vendor',
            $section
        );
        $this->assertStringContainsString(
            'execution attempt**',
            $section
        );
        $this->assertStringContainsString(
            'must **not** skip the invalid field and',
            $section
        );
        $this->assertStringContainsString(
            'continue processing remaining attributes',
            $section
        );
        $this->assertStringContainsString(
            'no `ConnectorSchemaSnapshot` row is published',
            $section
        );
        $this->assertStringContainsString(
            'no `ConnectorSchemaSnapshotField` rows are published',
            $section
        );
        $this->assertStringContainsString(
            'terminal result code is `DiscoverySchemaValidationFailed`',
            $section
        );
        $this->assertStringContainsString(
            'actionability is `support_required`',
            $section
        );
        $this->assertStringContainsString(
            'outcome is **non-retryable**',
            $section
        );
        $this->assertStringContainsString('`weight` was confirmed as a real `frontend_input` value', $section);
        $this->assertStringNotContainsString('`weight` is deliberately **excluded**', $section);
        $this->assertStringContainsString('placeholder first option', $section);
        $this->assertStringContainsString('Raw Adobe response bodies are never persisted', $section);
    }

    #[Test]
    public function domain_model_documents_adobe_raw_value_type_validation_rules(): void
    {
        $section = $this->adobeAttributeNormalizationSection();

        $this->assertStringContainsString('#### Raw value type validation (v1)', $section);
        $this->assertStringContainsString(
            'mapped Adobe string properties must arrive as JSON strings — no',
            $section
        );
        $this->assertStringContainsString(
            'int/bool/float-to-string coercion is performed',
            $section
        );
        $this->assertStringContainsString(
            '`attribute_code` and `frontend_input` are required, non-empty strings',
            $section
        );
        $this->assertStringContainsString(
            '`default_frontend_label`: missing/`null` → canonical `null`',
            $section
        );
        $this->assertStringContainsString(
            'selectable `options` must be decoded as a genuine JSON list',
            $section
        );
        $this->assertStringContainsString(
            'json_decode(..., associative: false)',
            $section
        );
        $this->assertStringContainsString(
            'each option row must decode as `\\stdClass` (a JSON object)',
            $section
        );
        $this->assertStringContainsString(
            'option `value` is required and must be a string (empty string valid)',
            $section
        );
        $this->assertStringContainsString(
            'option `label`: missing/`null` → canonical `null`',
            $section
        );
        $this->assertStringContainsString(
            'unknown keys inside an option row are ignored and never persisted',
            $section
        );
        $this->assertStringContainsString(
            'on a non-selectable type, any raw `options` value is ignored',
            $section
        );
        $this->assertStringContainsString(
            'no scalar coercion occurs anywhere in this normalizer',
            $section
        );
    }

    #[Test]
    public function tech_stack_documents_discovery_activation_gate_config_and_dual_enforcement(): void
    {
        $techStack = File::get(base_path('docs/07-TECH_STACK.md'));
        $connectorsConfig = File::get(base_path('config/connectors.php'));
        $envExample = File::get(base_path('.env.example'));

        $this->assertMatchesRegularExpression(
            "/'manual_trigger_enabled'\s*=>\s*env\(\s*'CONNECTOR_DISCOVERY_MANUAL_TRIGGER_ENABLED',\s*false,\s*\)/",
            $connectorsConfig
        );
        $this->assertStringContainsString('CONNECTOR_DISCOVERY_MANUAL_TRIGGER_ENABLED=false', $envExample);
        $this->assertStringContainsString("config('connectors.discovery.manual_trigger_enabled')", $techStack);
        $this->assertStringContainsString('Enforce in **two places**', $techStack);
        $this->assertStringContainsString(
            'the Filament manual-trigger action is **hidden**, not disabled',
            $techStack
        );
        $this->assertStringNotContainsString('hidden or disabled', $techStack);
        $this->assertStringContainsString(
            'disabled states remain the four feature states from discovery Scope 8 plus',
            $techStack
        );
        $this->assertStringContainsString(
            'source unavailable (`connectors.errors.discovery_source_unavailable`)',
            $techStack
        );
        $this->assertStringContainsString(
            'deployment gate is **not** a user-facing disabled state',
            $techStack
        );
        $this->assertStringContainsString(
            'This check runs **after** account/workspace',
            $techStack
        );
        $this->assertStringContainsString(
            'resolution and authorization, but **before** source resolution',
            $techStack
        );
        $this->assertStringContainsString(
            '`ConnectorDiscoveryRun` row creation, queue dispatch,',
            $techStack
        );
        $this->assertStringContainsString(
            'or HTTP work',
            $techStack
        );
        $this->assertStringContainsString('ConnectorDiscoveryManualTriggerDisabledException', $techStack);
        $this->assertStringContainsString(
            '`ConnectorDiscoveryRun` row is created and no HTTP call is made',
            $techStack
        );
        $this->assertStringContainsString('Post-merge activation runbook', $techStack);
        $this->assertStringContainsString('babypark-connector-queue', $techStack);
        $this->assertStringContainsString("'connection_check'", $connectorsConfig);
        $this->assertStringContainsString("'schema_discovery'", $connectorsConfig);
    }

    #[Test]
    public function domain_model_documents_discovery_transaction_phases(): void
    {
        $section = $this->connectorDiscoveryRunRuntimeSection();

        $this->assertStringContainsString(
            '#### Discovery dispatch and execution transaction phases (Resolved)',
            $section
        );
        $this->assertStringContainsString('Do not describe discovery (or connection-check) execution as "two', $section);
        $this->assertStringContainsString('transactions."', $section);
        $this->assertStringContainsString('**Phase A — dispatch-time reservation**', $section);
        $this->assertStringContainsString('**Phase B — execution-slot reservation**', $section);
        $this->assertStringContainsString('**Phase C — vendor execution**', $section);
        $this->assertStringContainsString('**Phase D — terminal finalization**', $section);
        $this->assertStringContainsString('reserveExecutionSlot()', $section);
        $this->assertStringContainsString('finalizeAfterVendorAttempt()', $section);
        $this->assertStringContainsString('terminalizeAttemptsExhausted()', $section);
    }

    #[Test]
    public function implementation_gaps_gap_006_records_closed_authorization_sub_gap_and_role_matrix(): void
    {
        $gap006 = $this->gap006Section();

        $this->assertStringContainsString('ConnectorAccount authorization/rendered-view sub-gap (closed', $gap006);
        $this->assertStringContainsString('ConnectorAccountMerchandiserPresentation', $gap006);
        $this->assertStringContainsString('`credentials`, `settings`, `base_url`', $gap006);
        $this->assertStringContainsString('`store_code`, `tenant_context`, `auth_profile`', $gap006);
        $this->assertStringContainsString('**GAP-006 overall remains Open.**', $gap006);
        $this->assertStringContainsString('| Admin | Yes | Yes (enabled accounts) | Yes |', $gap006);
        $this->assertStringContainsString('| Director | Yes | Yes (enabled accounts) | Yes |', $gap006);
        $this->assertStringContainsString(
            '| Manager, Warehouse, Programmer with `manage_connector_accounts` | Yes | Yes (enabled accounts) | Yes |',
            $gap006
        );
        $this->assertStringContainsString(
            '| Manager, Warehouse, Programmer without `manage_connector_accounts` | No | No | No |',
            $gap006
        );
        $this->assertStringContainsString('| Merchandiser | Yes | Yes (enabled accounts) | No |', $gap006);
        $this->assertStringContainsString('| Any role, cross-workspace account | No (404) | No (404) | No |', $gap006);
        $this->assertStringContainsString('| Disabled account | Per role matrix', $gap006);
    }

    #[Test]
    public function domain_model_documents_pre_dispatch_source_resolution_failure_ux(): void
    {
        $section = $this->connectorDiscoveryRunRuntimeSection();

        $this->assertStringContainsString('ConnectorDiscoverySourceResolutionException', $section);
        $this->assertStringContainsString('`missing` or `ambiguous`', $section);
        $this->assertStringContainsString('connectors.errors.discovery_source_unavailable', $section);
        $this->assertStringContainsString('pre-render disabled state', $section);
        $this->assertStringContainsString('match count (0 or the actual count for ambiguous)', $section);
        $this->assertStringContainsString('ConnectorSchemaSource.endpoint_path', $section);
        $this->assertStringContainsString('never a hardcoded Adobe path', $section);
    }

    #[Test]
    public function gap_024_tracks_laravel_11_upgrade_for_connector_production_readiness(): void
    {
        $gap024 = $this->gap024Section();

        $this->assertStringContainsString('**Status:** Open', $gap024);
        $this->assertStringContainsString('2026-03-12', $gap024);
        $this->assertStringContainsString('connector production-readiness', $gap024);
        $this->assertStringContainsString('Does **not** block this Task 4B-2-0 documentation promotion', $gap024);
        $this->assertStringContainsString('isolated Task 4B-2a development', $gap024);
        $this->assertStringContainsString('Must not be bundled into PR #86', $gap024);
        $this->assertStringContainsString('Task 4B-2a feature implementation', $gap024);
    }

    /**
     * @return non-empty-string
     */
    private function techStackConnectorRuntimeSection(string $content): string
    {
        if (! preg_match('/## Connector runtime \(Resolved — Task 4B-2-0\)\n\n(.*?)(?=\n---\n\n## Final Rule)/s', $content, $matches)) {
            $this->fail('Could not locate connector runtime section in 07-TECH_STACK.md');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function domainModelConnectionCheckMappingSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/### Connection-check capability and error mapping \(Resolved\)\n\n(.*?)(?=\n### Connection-check enqueue state)/s',
            $content,
            $matches
        )) {
            $this->fail('Could not locate B7 connection-check mapping section in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function proposalApprovalChecklistSection(string $content): string
    {
        if (! preg_match('/## Approval checklist\n\n(.*?)(?=\n## Application-code gate)/s', $content, $matches)) {
            $this->fail('Could not locate approval checklist in proposal file');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function connectorSchemaCanonicalHashingSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/### Connector schema canonical hashing \(Resolved\)\n\n'
            .'(.*?)'
            .'(?=\n### ConnectorSchemaDiff \/ ConnectorSchemaDiffItem \(Resolved\))/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate connector schema canonical hashing section');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function connectorDiscoveryRunRuntimeSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/#### Retry contract \(Resolved\)\n\n(.*?)'
            .'(?=\n### ConnectorSchemaSnapshot \(Resolved\))/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate connector discovery runtime section');
        }

        $retrySection = $matches[1];

        if (! preg_match(
            '/#### Discovery dispatch and execution transaction phases \(Resolved\)\n\n(.*?)(?=\n#### Deterministic latest-snapshot ordering)/s',
            $content,
            $phaseMatches,
        )) {
            $this->fail('Could not locate discovery transaction phases section');
        }

        return $phaseMatches[1].$retrySection;
    }

    /**
     * @return non-empty-string
     */
    private function adobeAttributeNormalizationSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/(### Adobe attribute normalization \(Resolved\)\n\n'
            .'.*?)'
            .'(?=\n### Connector schema canonical hashing \(Resolved\))/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Adobe attribute normalization section');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function gap024Section(): string
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        if (! preg_match('/## GAP-024 —.*?(?=\n## GAP-021 —)/s', $content, $matches)) {
            $this->fail('Could not locate GAP-024 section in IMPLEMENTATION_GAPS.md');
        }

        return $matches[0];
    }
}
