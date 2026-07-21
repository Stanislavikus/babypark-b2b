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
    public function implementation_gaps_documents_task_4b0_4b1_4b2_4c_sequence(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('**4B-0**', $content);
        $this->assertStringContainsString('**4B-1**', $content);
        $this->assertStringContainsString('**4B-2**', $content);
        $this->assertStringContainsString('**4C**', $content);
    }

    #[Test]
    public function implementation_gaps_keeps_gap_006_open(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('GAP-006 stays Open', $content);
        $this->assertStringContainsString('**Status:** Open', $content);
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
}
