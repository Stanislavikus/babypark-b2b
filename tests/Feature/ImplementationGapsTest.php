<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImplementationGapsTest extends TestCase
{
    #[Test]
    public function gap_006_contains_task_4b_ui_handoff(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('GAP-006 — Connector / Import / FieldMapping infrastructure absent', $content);
        $this->assertStringContainsString('**Task 4B UI handoff:**', $content);
        $this->assertStringContainsString('Task 4B must align all Eloquent list filters it materially touches with', $content);
        $this->assertStringContainsString('06-UI_DESIGN_SYSTEM.md', $content);
    }

    #[Test]
    public function gap_006_keeps_connector_account_credentials_out_of_connector_definition(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString(
            'Workspace API credentials, store/account base URLs, connection status,',
            $content
        );
        $this->assertStringContainsString('`ConnectorAccount`; do not store them on `ConnectorDefinition`.', $content);
        $this->assertStringContainsString('last sync and live account discovery belong to workspace-owned', $content);
    }

    #[Test]
    public function gap_006_keeps_field_mapping_runtime_in_task_4c(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString(
            'Automatic field-match suggestions, confidence handling, persistence of',
            $content
        );
        $this->assertStringContainsString('to the subsequent FieldMapping workflow (Task 4C), not to', $content);
        $this->assertStringContainsString('ConnectorDefinition metadata.', $content);
    }

    #[Test]
    public function gap_025_records_correct_sync_backend_truth_not_unimplemented_configuration(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('SyncConfiguration,', $content);
        $this->assertStringContainsString('FieldMapping persistence, and canonical suggestion read-model implemented in', $content);
        $this->assertStringContainsString('Layer B mapping UI still missing', $content);
        $this->assertStringNotContainsString(
            'Sync Domain persistence/runtime (`SyncConfiguration`, `FieldMapping`,',
            $content,
        );
    }

    #[Test]
    public function gap_025_marks_connector_account_authorization_as_transitional_under_gap_026(): void
    {
        $content = $this->gap025Section();

        $this->assertStringContainsString('**Shipped but transitional authorization:**', $content);
        $this->assertStringContainsString(
            'fixed `User.role` authorization behavior is transitional',
            $content,
        );
        $this->assertStringContainsString(
            'under **GAP-026** — not normative target authorization',
            $content,
        );
        $this->assertStringNotContainsString(
            'remaining work is labeling, Layer C gating, and deeper Layer A/B surfaces',
            $content,
        );
        $this->assertStringContainsString(
            '**GAP-026** backend/security work and remains prerequisite for mutable Layer-B',
            $content,
        );
        $this->assertStringContainsString(
            'not merely labeling/navigation/gating UI work',
            $content,
        );
        $this->assertStringNotContainsString(
            'Do not widen workspace Admin to Layer C as a workaround',
            $content,
        );
        $this->assertStringContainsString(
            'Do not widen any workspace merchant membership / role-access profile to Layer C',
            $content,
        );
        $this->assertMatchesRegularExpression(
            '/Layer C requires the separately resolved platform-support\s+identity/',
            $content,
        );
    }

    #[Test]
    public function gap_006_role_matrix_is_labeled_historical_transitional_not_normative(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString(
            '**Historical/transitional shipped role matrix (pre-4C-1c-2a current implementation; not normative target authorization; superseded by the GAP-026 workspace-scoped RBAC contract):**',
            $content,
        );
        $this->assertStringContainsString('| Admin | Yes | Yes (enabled accounts) | Yes |', $content);
        $this->assertStringContainsString('| Merchandiser | Yes | Yes (enabled accounts) | No |', $content);
    }

    #[Test]
    public function gap_026_records_workspace_scoped_rbac_status(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('## GAP-026 — Workspace-scoped RBAC foundation not implemented', $content);
        $this->assertStringContainsString('**Frozen minimum permission vocabulary (implemented in GAP-026A-1):**', $content);
        $this->assertStringContainsString('`view_connector_accounts`', $content);
        $this->assertStringContainsString('`run_connector_discovery`', $content);
        $this->assertStringContainsString('`manage_connector_accounts`', $content);
        $this->assertStringContainsString('`view_sync_mappings`', $content);
        $this->assertStringContainsString('`manage_sync_mappings`', $content);
        $this->assertStringContainsString('`manage_workspace_access`', $content);
        $this->assertStringContainsString('`manage_workspace_tax_settings`', $content);
        $this->assertStringContainsString('Physical persistence is **resolved** in GAP-026-0', $content);
        $this->assertStringContainsString('GAP-026A-1 — Schema, catalogue & explicit read authorization', $content);
        $this->assertStringContainsString('GAP-026A-2 — Preflight/backfill machinery & anti-lockout coordinator', $content);
        $this->assertStringContainsString('**Done.** `WorkspaceRbacLegacyPreflight`', $content);
        $this->assertStringContainsString('GAP-026A (overall)** | **Done**', $content);
        $this->assertStringContainsString('GAP-026B-0 — Workspace RBAC authority cutover contract', $content);
        $this->assertStringContainsString('GAP-026B-1 — Access & Cutover Machinery', $content);
        $this->assertStringContainsString('GAP-026B-2 — Authority & Presentation Cutover', $content);
        $this->assertStringContainsString('anti-lockout', $content);
        $this->assertStringContainsString('WorkspaceRbacPermissionSeeder', $content);
        $this->assertStringContainsString('WorkspaceAuthorization', $content);
        $this->assertStringContainsString("'teams' => false", $content);
        $this->assertStringContainsString('GAP-026A-2', $content);
        $this->assertStringContainsString('physical architecture frozen (GAP-026-0)', $content);
        $this->assertStringContainsString('Open / partial', $content);
        $this->assertStringContainsString('GAP-026B-0 cutover contract **Done**', $content);
        $this->assertStringContainsString('026B-1 / GAP-026B-2 runtime **unimplemented**', $content);
        $this->assertStringContainsString('Production backfill runs at **EXECUTE** during the maintenance-window cutover', $content);
        $this->assertStringContainsString('failure at any step = STOP, no partial cutover', $content);
        $this->assertStringContainsString('Legacy membership / role backfill matrix (026B production execution — GAP-026B-2 EXECUTE only)', $content);
        $this->assertStringContainsString('Spatie preflight must complete before legacy backfill', $content);
        $this->assertStringContainsString('Failure halts authorization cutover — no partial RBAC', $content);
        $this->assertStringContainsString('Legacy User lifecycle (026B cutover compatibility)', $content);
        $this->assertStringContainsString('Do not weaken RESTRICT FKs', $content);
        $this->assertStringContainsString('4C-1c-2b remains blocked until GAP-026B-2', $content);
    }

    #[Test]
    public function gap_026b_slice_placement_separates_check_only_and_execute(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        if (! preg_match(
            '/\| \*\*GAP-026B-1 — Access & Cutover Machinery\*\* \| (.*?) \|/s',
            $content,
            $b1Matches,
        )) {
            $this->fail('Could not locate GAP-026B-1 slice row in IMPLEMENTATION_GAPS.md');
        }

        if (! preg_match(
            '/\| \*\*GAP-026B-2 — Authority & Presentation Cutover\*\* \| (.*?) \|/s',
            $content,
            $b2Matches,
        )) {
            $this->fail('Could not locate GAP-026B-2 slice row in IMPLEMENTATION_GAPS.md');
        }

        $b1 = $b1Matches[1];
        $b2 = $b2Matches[1];

        $this->assertStringContainsString('CHECK-ONLY only', $b1);
        $this->assertStringContainsString('no RBAC assignment/materialization', $b1);
        $this->assertStringContainsString('No executable production EXECUTE mode in a B-1-only release', $b1);
        $this->assertStringContainsString('Explicitly no** connector/tax policy authority switch', $b1);
        $this->assertStringNotContainsString('**EXECUTE mode** of guarded cutover', $b1);

        $this->assertStringContainsString('EXECUTE mode', $b2);
        $this->assertStringContainsString('production legacy backfill/materialization', $b2);
        $this->assertStringContainsString('ConnectorAccountPolicy', $b2);
        $this->assertStringContainsString('maintenance-window cutover deployment', $b2);
        $this->assertStringContainsString('EXECUTE + anti-lockout + smoke succeed', $b2);

        $this->assertStringContainsString('CHECK-ONLY (B-1)', $content);
        $this->assertStringContainsString('EXECUTE at cutover — B-2 only', $content);
        $this->assertStringContainsString('GAP-026B-2 EXECUTE only', $content);
    }

    #[Test]
    public function gap_027_records_platform_admin_resource_rbac(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('## GAP-027 — Platform-wide admin Resource RBAC', $content);
        $this->assertStringContainsString('new staff membership onboarding', $content);
        $this->assertStringContainsString('existing-memberships-only limitation', $content);
        $this->assertStringContainsString('authorization-coverage CI guard', $content);
        $this->assertStringContainsString('do not enable global Filament strict authorization prematurely', $content);
    }

    /**
     * @return non-empty-string
     */
    private function gap025Section(): string
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        if (! preg_match(
            '/## GAP-025 — Connector Integration UX contract not yet migrated in shipped UI\n\n(.*?)(?=\n---\n\n## GAP-026 —)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate GAP-025 section in IMPLEMENTATION_GAPS.md');
        }

        return $matches[1];
    }
}
