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
            '/\*\*4B-2a\*\*.*connection list.*connection check\/result UI/s',
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
        $this->assertStringContainsString('GAP-016 and GAP-017 are Closed in code', $gap006);
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
        $this->assertStringContainsString('**Status:** Open. Unblocked', $gap006);
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
    public function promoted_task_4b2_0_runtime_decisions_exist_in_core_docs(): void
    {
        $domainModel = File::get(base_path('docs/03-DOMAIN_MODEL.md'));
        $architecture = File::get(base_path('docs/04-ARCHITECTURE_PRINCIPLES.md'));
        $aiAgreement = File::get(base_path('docs/05-AI_WORKING_AGREEMENT.md'));
        $uiDesign = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));
        $techStack = File::get(base_path('docs/07-TECH_STACK.md'));
        $gaps = $this->gap006Section();

        $this->assertStringContainsString('### Connector adapter capabilities (proposed)', $domainModel);
        $this->assertStringContainsString('#### Credential and settings classification (proposed)', $domainModel);
        $this->assertMatchesRegularExpression(
            '/reusing `store_code` for the `Store`\s+header value is the preferred convention pending approval/',
            $domainModel
        );
        $this->assertStringContainsString('### ConnectorAccount authorization (Resolved)', $domainModel);
        $this->assertStringContainsString('Merchandiser may run **manual** discovery', $domainModel);
        $this->assertStringContainsString('### Connection-check capability and error mapping (Resolved)', $domainModel);
        $this->assertStringContainsString('### Connection-check enqueue state (Resolved)', $domainModel);
        $this->assertStringContainsString('add `Queued` to `ConnectorConnectionCheckStatus`', $domainModel);

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
        $this->assertStringContainsString('must not be promoted here as a pre-approved dependency', $connectorRuntime);
        $this->assertStringContainsString('### Connector queue workers (production)', $connectorRuntime);
        $this->assertStringContainsString('### Connector idempotency and overlap locking (Resolved)', $connectorRuntime);
        $this->assertStringContainsString('MVP connector jobs do **not** implement `ShouldBeUnique`', $connectorRuntime);
        $this->assertStringContainsString('Dispatch failure compensation', $connectorRuntime);
        $this->assertStringContainsString('### Connector timeout and retry policy (Resolved)', $connectorRuntime);
        $this->assertStringContainsString('### Queue timeout alignment (Resolved)', $connectorRuntime);
        $this->assertStringContainsString('`pcntl` PHP extension', $connectorRuntime);
        $this->assertStringContainsString('### SSRF-safe connector outbound transport', $connectorRuntime);
        $this->assertStringContainsString('CURLOPT_RESOLVE', $connectorRuntime);
        $this->assertStringContainsString('### Connector secret lifecycle (Resolved)', $connectorRuntime);
        $this->assertStringContainsString('APP_PREVIOUS_KEYS', $connectorRuntime);

        $this->assertStringContainsString('**Task 4B-2-0 note (added 2026-07-22):**', $gaps);
        $this->assertStringContainsString('SaaS `Store`-header vs `store_code` reuse (B3)', $gaps);
        $this->assertStringContainsString('Production queue-worker verification', $gaps);
        $this->assertStringContainsString('non-blocking for 4B-2a', $gaps);
        $this->assertStringContainsString('**GAP-024**', $gaps);
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
    private function gap024Section(): string
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        if (! preg_match('/## GAP-024 —.*?(?=\n## GAP-021 —)/s', $content, $matches)) {
            $this->fail('Could not locate GAP-024 section in IMPLEMENTATION_GAPS.md');
        }

        return $matches[0];
    }
}
