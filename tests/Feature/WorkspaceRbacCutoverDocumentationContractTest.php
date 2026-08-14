<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkspaceRbacCutoverDocumentationContractTest extends TestCase
{
    #[Test]
    public function domain_model_documents_gap_026b_0_authority_cutover_section(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '### Workspace RBAC authority cutover [Resolved — GAP-026B-0, 2026-08-13]',
            $content,
        );
    }

    #[Test]
    public function authority_cutover_freezes_transitional_vs_authoritative_paths(): void
    {
        $section = $this->cutoverSection();

        $this->assertStringContainsString('User.role', $section);
        $this->assertStringContainsString('WorkspaceMembership', $section);
        $this->assertStringContainsString('global Spatie permissions', $section);
        $this->assertStringContainsString('active WorkspaceUser', $section);
        $this->assertStringContainsString('WorkspaceRole(s)', $section);
        $this->assertStringContainsString('canonical WorkspacePermission(s)', $section);
        $this->assertStringContainsString('legacy authorization remains transitional until **GAP-027**', $section);
        $this->assertStringContainsString('complete platform-wide RBAC', $section);
    }

    #[Test]
    public function connector_read_or_set_is_frozen(): void
    {
        $section = $this->cutoverSection();

        $this->assertStringContainsString('`view_connector_accounts`', $section);
        $this->assertStringContainsString('`run_connector_discovery`', $section);
        $this->assertStringContainsString('`manage_connector_accounts`', $section);
        $this->assertStringContainsString('at least one', $section);
        $this->assertStringContainsString('Role names do **not** contribute', $section);
    }

    #[Test]
    public function connector_discovery_and_management_permissions_are_frozen(): void
    {
        $section = $this->cutoverSection();

        $this->assertStringContainsString('Discovery control — visible/eligible when:', $section);
        $this->assertStringContainsString('`run_connector_discovery` **OR** `manage_connector_accounts`', $section);
        $this->assertStringContainsString('Management — **only** `manage_connector_accounts`', $section);
        $this->assertStringContainsString('connection check', $section);
        $this->assertStringContainsString('legacy Spatie grants have **no** connector authorization', $section);
    }

    #[Test]
    public function connector_presentation_is_permission_based_not_user_role(): void
    {
        $section = $this->cutoverSection();

        $this->assertStringContainsString('never job-title-based', $section);
        $this->assertStringContainsString('regardless of legacy `User.role`', $section);
        $this->assertStringContainsString('`credentials`', $section);
        $this->assertStringContainsString('`settings`', $section);
        $this->assertStringContainsString('`base_url`', $section);
        $this->assertStringContainsString('`store_code`', $section);
        $this->assertStringContainsString('`tenant_context`', $section);
        $this->assertStringContainsString('ConnectorAccountMerchandiserPresentation', $section);
        $this->assertStringContainsString('User.role === Merchandiser', $section);
    }

    #[Test]
    public function workspace_membership_is_not_additional_connector_authority_gate(): void
    {
        $section = $this->cutoverSection();

        $this->assertStringContainsString(
            'WorkspaceMembership` is **not** an additional authorization gate',
            $section,
        );
        $this->assertStringContainsString('ConnectorAccountSettingsService', $section);
        $this->assertStringContainsString('WorkspaceMembership::belongs()', $section);
        $this->assertStringContainsString('Do **not** globally rewrite or delete `WorkspaceMembership`', $section);
    }

    #[Test]
    public function tax_authority_requires_explicit_workspace_and_manage_workspace_tax_settings(): void
    {
        $section = $this->cutoverSection();

        $this->assertStringContainsString('manage_workspace_tax_settings', $section);
        $this->assertStringContainsString('WorkspaceAuthorization::allows', $section);
        $this->assertStringContainsString('No Admin/Director special case', $section);
        $this->assertStringContainsString('must receive that resolved `Workspace` explicitly', $section);
        $this->assertStringContainsString('TOCTOU protection', $section);
        $this->assertStringContainsString('re-authorize against the current explicit `Workspace`', $section);
    }

    #[Test]
    public function mapping_view_and_manage_semantics_are_frozen_and_independent_from_connector(): void
    {
        $section = $this->cutoverSection();

        $this->assertStringContainsString('`view_sync_mappings` **OR** `manage_sync_mappings`', $section);
        $this->assertStringContainsString('Mapping mutation | `manage_sync_mappings`', $section);
        $this->assertStringContainsString(
            '`manage_connector_accounts` does **not** imply either Mapping permission',
            $section,
        );
        $this->assertStringContainsString(
            'Mapping permissions do **not** imply Connector settings/credential access',
            $section,
        );
        $this->assertStringContainsString('FieldMappingMutationService', $section);
        $this->assertStringContainsString('4C-1c-2b', $section);
    }

    #[Test]
    public function access_ui_manages_existing_memberships_only(): void
    {
        $section = $this->cutoverSection();

        $this->assertStringContainsString('existing `WorkspaceUser` only', $section);
        $this->assertStringContainsString('assign one or more existing roles', $section);
        $this->assertStringContainsString('deactivate membership', $section);
        $this->assertStringContainsString('reactivate membership', $section);
        $this->assertStringContainsString('create merchant custom role', $section);
        $this->assertStringContainsString('edit role\'s canonical seven-permission bundle', $section);
        $this->assertStringContainsString('manage_workspace_access', $section);
        $this->assertStringContainsString('WorkspaceAccessMutationCoordinator', $section);
        $this->assertStringContainsString('do **not** invent a separate `view_workspace_access`', $section);
    }

    #[Test]
    public function access_ui_defers_invite_and_direct_overrides(): void
    {
        $section = $this->cutoverSection();

        $this->assertStringContainsString('does **NOT** create or attach new `WorkspaceUser` memberships', $section);
        $this->assertStringContainsString('invite employee', $section);
        $this->assertStringContainsString('membership hard-delete', $section);
        $this->assertStringContainsString('must **not** present a functional Add user / Invite user action', $section);
        $this->assertStringContainsString(
            'Adding new users will be available in the next access-management stage',
            $section,
        );
        $this->assertStringContainsString('direct per-user permission overrides', $section);
        $this->assertStringContainsString('deny/muting', $section);
    }

    #[Test]
    public function gap_027_explicitly_owns_onboarding_and_limitation_removal(): void
    {
        $gaps = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));
        $domain = $this->cutoverSection();

        $this->assertStringContainsString('Removal is owned by **GAP-027**', $domain);
        $this->assertStringContainsString('new staff membership onboarding', $gaps);
        $this->assertStringContainsString('existing-memberships-only limitation', $gaps);
        $this->assertStringContainsString('no** `WorkspaceUser` automatically', $gaps);
        $this->assertStringContainsString('authorize adding a role-based fallback', $gaps);
        $this->assertStringContainsString('Access UI must not expose Add/Invite until this ships', $gaps);
    }

    #[Test]
    public function user_lifecycle_transition_rules_are_frozen(): void
    {
        $section = $this->cutoverSection();

        $this->assertStringContainsString(
            'changing `User.role` **MUST NOT** synchronize, create, delete, or replace',
            $section,
        );
        $this->assertStringContainsString('`customer_id`', $section);
        $this->assertStringContainsString('not Workspace RBAC authority', $section);
        $this->assertStringContainsString('does not recreate/reset roles or membership state', $section);
        $this->assertStringContainsString('deterministic `workspace_id` order', $section);
        $this->assertStringContainsString('hard delete **denied**', $section);
        $this->assertStringContainsString('Do **not** introduce a new `User`-row mutex', $section);
        $this->assertStringContainsString('Do **not** weaken `users` → `workspace_users` ON DELETE RESTRICT', $section);
    }

    #[Test]
    public function deployment_contract_freezes_cutover_sequence_and_command_modes(): void
    {
        $section = $this->cutoverSection();
        $deploy = File::get(base_path('DEPLOY.md'));
        $b2Row = $this->cutoverB2SliceRow();

        $this->assertStringContainsString('CHECK-ONLY', $section);
        $this->assertStringContainsString('EXECUTE', $b2Row);
        $this->assertStringContainsString('maintenance mode / explicitly quiesced', $section);
        $this->assertStringContainsString('no partial authority fallback', $section);
        $this->assertStringContainsString('Merging 026B code into `develop` is **not** itself production cutover', $section);
        $this->assertStringContainsString('Do **not** use a permanent dual-authority mode', $section);
        $this->assertStringContainsString('First B-2 production deployment', $section);
        $this->assertStringContainsString('maintenance-window cutover deployment', $section);

        $this->assertStringContainsString('### GAP-026B one-time Workspace RBAC cutover', $deploy);
        $this->assertStringContainsString('Ordinary recurring deployment', $deploy);
        $this->assertStringContainsString('does **not** run migrations', $deploy);
        $this->assertStringContainsString('Repository merge ≠ production cutover', $deploy);
        $this->assertStringContainsString('CHECK-ONLY', $deploy);
        $this->assertStringContainsString('EXECUTE', $deploy);
        $this->assertStringContainsString('GAP-026B-1 + GAP-026B-2', $deploy);
        $this->assertStringContainsString('First B-2 production deployment', $deploy);
        $this->assertStringContainsString('Do **not** introduce persistent activation flags', $deploy);
    }

    #[Test]
    public function access_mutation_contract_requires_post_lock_fresh_authorization_and_target_revalidation(): void
    {
        $section = $this->cutoverSection();

        $antiLockoutPos = strpos($section, '**Anti-lockout routing for Access mutations (026B applicability)**');
        $this->assertNotFalse($antiLockoutPos);

        $accessSection = substr($section, $antiLockoutPos);

        $this->assertStringContainsString('optional preliminary authorization for early rejection only', $accessSection);
        $this->assertStringContainsString('non-authoritative fast-fail/UX', $accessSection);
        $this->assertStringContainsString('`WorkspaceAccessMutationCoordinator` acquires the explicit `Workspace` row mutex', $accessSection);
        $this->assertStringContainsString('freshly reload the requesting `User` from persistence by stable ID', $accessSection);
        $this->assertStringContainsString('fresh actor authorization against the locked explicit `Workspace`', $accessSection);
        $this->assertStringContainsString('freshly resolve/revalidate mutable membership/role targets', $accessSection);
        $this->assertStringContainsString('fresh effective-holder query', $accessSection);
        $this->assertStringContainsString('reject/rollback if zero holders', $accessSection);
        $this->assertStringContainsString('The coordinator remains an integrity boundary, **not** actor authorization', $accessSection);
        $this->assertStringContainsString('Do **not** merge actor authorization into `WorkspaceAccessMutationCoordinator`', $accessSection);
        $this->assertStringContainsString('same TOCTOU principle already frozen for consequential Tax writes', $accessSection);

        $lockPos = strpos($accessSection, 'acquires the explicit `Workspace` row mutex');
        $freshAuthPos = strpos($accessSection, 'fresh actor authorization against the locked explicit `Workspace`');
        $freshTargetPos = strpos($accessSection, 'freshly resolve/revalidate mutable membership/role targets');
        $mutationPos = strpos($accessSection, 'perform the mutation');
        $holderQueryPos = strpos($accessSection, 'fresh effective-holder query');
        $rollbackPos = strpos($accessSection, 'reject/rollback if zero holders');

        $this->assertNotFalse($lockPos);
        $this->assertNotFalse($freshAuthPos);
        $this->assertNotFalse($freshTargetPos);
        $this->assertNotFalse($mutationPos);
        $this->assertNotFalse($holderQueryPos);
        $this->assertNotFalse($rollbackPos);

        $this->assertLessThan($freshAuthPos, $lockPos);
        $this->assertLessThan($freshTargetPos, $freshAuthPos);
        $this->assertLessThan($mutationPos, $freshTargetPos);
        $this->assertLessThan($holderQueryPos, $mutationPos);
        $this->assertLessThan($rollbackPos, $holderQueryPos);
    }

    #[Test]
    public function gap_026b_1_slice_owns_check_only_only_not_execute_or_production_backfill(): void
    {
        $domainB1 = $this->cutoverB1SliceRow();
        $gapsB1 = $this->gap026b1SliceRow();

        $this->assertStringContainsString('CHECK-ONLY', $domainB1);
        $this->assertStringContainsString('no RBAC assignment/materialization', $domainB1);
        $this->assertStringContainsString('Explicitly no** connector/tax policy authority switch', $domainB1);
        $this->assertStringContainsString('B-1-only release must not ship an executable production EXECUTE mode', $domainB1);
        $this->assertStringNotContainsString('**EXECUTE mode** of the guarded', $domainB1);

        $this->assertStringContainsString('CHECK-ONLY `workspace-rbac:cutover-check`', $gapsB1);
        $this->assertStringContainsString('EXECUTE ships with B-2', $gapsB1);
        $this->assertStringContainsString('Explicitly no** connector/tax policy authority switch', $gapsB1);
        $this->assertStringContainsString('B-1-only release must not ship an executable production EXECUTE mode', $domainB1);
        $this->assertStringNotContainsString('**EXECUTE mode** of guarded cutover', $gapsB1);
    }

    #[Test]
    public function connector_dispatch_authorization_freshness_is_frozen(): void
    {
        $section = $this->cutoverSection();

        $this->assertStringContainsString('**Connector dispatch authorization freshness (frozen)**', $section);
        $this->assertStringContainsString('optional preliminary authorization for early rejection', $section);
        $this->assertStringContainsString('existing `ConnectorAccount` critical-section lock', $section);
        $this->assertStringContainsString('post-lock check is the **authoritative** dispatch authorization decision', $section);
        $this->assertStringContainsString('pre-lock hydrated `User` object is **not** authorization truth', $section);
        $this->assertStringContainsString('global `users.is_active`', $section);
        $this->assertStringContainsString('`workspace_users.is_active`', $section);
        $this->assertStringContainsString('current `WorkspaceRole` assignments', $section);
        $this->assertStringContainsString('current canonical `WorkspacePermission` assignments', $section);
        $this->assertStringContainsString('GAP-026B-2 implementation **must** evaluate those authority inputs through one', $section);
        $this->assertStringContainsString('database-backed effective-permission projection/query', $section);
        $this->assertStringContainsString('rather than trusting', $section);
        $this->assertStringContainsString('`User.is_active` from the supplied Eloquent instance', $section);
        $this->assertStringContainsString('`WorkspaceAuthorization::effectivePermissions()` must **not** rely on a', $section);
        $this->assertStringContainsString('sequence of partially stale hydrated-model checks as equivalent compliance', $section);
        $this->assertStringContainsString('scoped to the explicit `Workspace`', $section);
    }

    #[Test]
    public function connector_dispatch_revocation_boundary_is_frozen(): void
    {
        $section = $this->cutoverSection();

        $this->assertStringContainsString('**Connector dispatch revocation boundary (frozen residual)**', $section);
        $this->assertStringContainsString('point-in-time post-lock authorization snapshot', $section);
        $this->assertStringContainsString('commits **before** the authoritative post-lock authorization', $section);
        $this->assertStringContainsString('fail closed', $section);
        $this->assertStringContainsString('commits **after** that snapshot, GAP-026B-2 does **not** require another', $section);
        $this->assertStringContainsString('initiating-actor authorization check before the enqueue transaction commits', $section);
        $this->assertStringContainsString('already-authorized in-flight enqueue transaction may complete', $section);
        $this->assertStringContainsString('Already queued/running Connector work is **not** retroactively cancelled', $section);
        $this->assertStringContainsString('do **not** re-authorize the initiating `User` at execution time', $section);
        $this->assertStringContainsString('live workspace authorization', $section);
        $this->assertStringContainsString('Do **not** call this “authorized as of transaction commit”', $section);
        $this->assertStringContainsString('no shared serialization lock', $section);
        $this->assertStringContainsString('guarantees authority at commit time', $section);
        $this->assertStringContainsString('Future cancellation-on-revocation remains a new Stop-and-Amend', $section);
    }

    #[Test]
    public function connector_dispatch_rejects_workspace_and_user_row_mutex(): void
    {
        $section = $this->cutoverSection();

        $this->assertStringContainsString('**Connector dispatch must not acquire Workspace anti-lockout mutex (frozen)**', $section);
        $this->assertStringContainsString('must **not** acquire the `Workspace` anti-lockout row mutex', $section);
        $this->assertStringContainsString('Preserve existing per-`ConnectorAccount` dispatch serialization', $section);
        $this->assertStringContainsString('Do **not** add a `User`-row mutex', $section);
        $this->assertStringContainsString('Do **not** couple Connector dispatch to Access anti-lockout locking', $section);
        $this->assertStringContainsString('lock-domain separation and per-account granularity', $section);
    }

    #[Test]
    public function workspace_authorization_stale_model_distinction_is_frozen_for_access(): void
    {
        $section = $this->cutoverSection();

        $antiLockoutPos = strpos($section, '**Anti-lockout routing for Access mutations (026B applicability)**');
        $this->assertNotFalse($antiLockoutPos);

        $accessSection = substr($section, $antiLockoutPos);

        $this->assertStringContainsString('**post-B-2 repository implementation:**', $accessSection);
        $this->assertStringContainsString('database-backed projection', $accessSection);
        $this->assertStringContainsString('not from the supplied Eloquent `User` instance', $accessSection);
        $this->assertStringContainsString('Access post-lock fresh `User` reload by stable ID remains required', $accessSection);
        $this->assertStringContainsString('removed merely because the central authorization query becomes DB-backed', $accessSection);
        $this->assertStringContainsString('`WorkspaceAccessMutationCoordinator` acquires the explicit `Workspace` row mutex', $accessSection);
    }

    #[Test]
    public function gap_026b_2_slice_owns_execute_with_authority_cutover(): void
    {
        $domainB2 = $this->cutoverB2SliceRow();
        $gapsB2 = $this->gap026b2SliceRow();

        $this->assertStringContainsString('EXECUTE mode', $domainB2);
        $this->assertStringContainsString('ConnectorAccountPolicy', $domainB2);
        $this->assertStringContainsString('permission-based safe Connector presentation', $domainB2);
        $this->assertStringContainsString('production-activated (2026-08-14)', $domainB2);
        $this->assertStringContainsString('maintenance-window cutover completed 2026-08-14', $domainB2);
        $this->assertStringContainsString('4C-1c-2b** may proceed', $domainB2);

        $this->assertStringContainsString('EXECUTE command', $gapsB2);
        $this->assertStringContainsString('workspace-rbac:cutover-execute', $gapsB2);
        $this->assertStringContainsString('ConnectorAccountPolicy', $gapsB2);
        $this->assertStringContainsString('maintenance-window cutover', $gapsB2);
        $this->assertStringContainsString('production-activated (2026-08-14)', $gapsB2);
        $this->assertStringContainsString('fb2c5a7a3f8a521a2bfca7583e57d1ae83e95bc9', $gapsB2);

        $this->assertStringContainsString('do **not** re-authorize the initiating `User` at execution time', $this->cutoverSection());
    }

    #[Test]
    public function cutover_contract_forbids_b1_only_execute_and_dual_authority_activation(): void
    {
        $section = $this->cutoverSection();

        $this->assertStringContainsString('B-1-only EXECUTE is **forbidden by slice placement**', $section);
        $this->assertStringContainsString('non-authoritative shadow RBAC graph', $section);
        $this->assertStringContainsString('stale grants that later become authoritative', $section);
        $this->assertStringContainsString('Do **not** introduce `--confirm-maintenance-window`', $section);
        $this->assertStringContainsString('persistent environment activation', $section);
        $this->assertStringContainsString('marker tables', $section);
        $this->assertStringContainsString('dual-authority policy switches', $section);
        $this->assertStringContainsString('never** fall back to legacy roles', $section);
    }

    #[Test]
    public function staging_splits_026b_1_and_026b_2_and_4c_1c_2b_prerequisite_satisfied(): void
    {
        $gaps = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('CHECK-ONLY mode only', $this->cutoverB1SliceRow());
        $this->assertStringContainsString('EXECUTE mode', $this->cutoverB2SliceRow());
        $this->assertStringContainsString('4C-1c-2b** may proceed', $this->cutoverB2SliceRow());

        $this->assertStringContainsString('GAP-026B-0 — Workspace RBAC authority cutover contract', $gaps);
        $this->assertStringContainsString('CHECK-ONLY (B-1)', $gaps);
        $this->assertStringContainsString('EXECUTE ships with B-2', $gaps);
        $this->assertStringContainsString('GAP-026A (overall)** | **Done**', $gaps);
        $this->assertStringContainsString('GAP-026B-1 **Done**', $gaps);
        $this->assertStringContainsString('Part 2 merchant Access/Roles UI', $gaps);
        $this->assertStringContainsString('workspace-rbac:cutover-check', $gaps);
        $this->assertStringContainsString('GAP-026B authorization prerequisite is now satisfied', $gaps);
        $this->assertStringContainsString('GAP-026B (overall)** | **Done**', $gaps);
    }

    #[Test]
    public function connector_ux_contract_documents_capability_based_presentation(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString('connector safe presentation — Resolved — GAP-026B-0', $content);
        $this->assertStringContainsString('capability-based**, never job-title-based', $content);
        $this->assertStringContainsString('before** merchant-facing Livewire/Filament record serialization', $content);
    }

    #[Test]
    public function ui_design_system_documents_026b_access_scope(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('026B Access / Roles scope (Resolved — GAP-026B-0', $content);
        $this->assertStringContainsString('existing `WorkspaceUser` memberships only', $content);
        $this->assertStringContainsString('invite / add user', $content);
        $this->assertStringContainsString('Adding new users will be available in the next access-management stage', $content);
        $this->assertStringContainsString('New membership onboarding is owned by', $content);
        $this->assertStringContainsString('**GAP-027**', $content);
    }

    #[Test]
    public function project_documentation_map_records_cutover_decision(): void
    {
        $content = File::get(base_path('docs/Project_Documentation_Map.md'));

        $this->assertStringContainsString('Workspace RBAC authority cutover (GAP-026B-0)', $content);
        $this->assertStringContainsString('existing-memberships-only Access management', $content);
        $this->assertStringContainsString('new-membership onboarding deferred to GAP-027', $content);
    }

    /**
     * @return non-empty-string
     */
    private function cutoverSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/### Workspace RBAC authority cutover \[Resolved — GAP-026B-0, 2026-08-13\]\n\n(.*?)(?=\n## Product Catalogue Context)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Workspace RBAC authority cutover section in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function cutoverB1SliceRow(): string
    {
        $section = $this->cutoverSection();

        if (! preg_match(
            '/\| \*\*GAP-026B-1 — Access & Cutover Machinery\*\* \| (.*?) \|/s',
            $section,
            $matches,
        )) {
            $this->fail('Could not locate GAP-026B-1 slice row in 03-DOMAIN_MODEL.md cutover section');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function cutoverB2SliceRow(): string
    {
        $section = $this->cutoverSection();

        if (! preg_match(
            '/\| \*\*GAP-026B-2 — Authority & Presentation Cutover\*\* \| (.*?) \|/s',
            $section,
            $matches,
        )) {
            $this->fail('Could not locate GAP-026B-2 slice row in 03-DOMAIN_MODEL.md cutover section');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function gap026b1SliceRow(): string
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        if (! preg_match(
            '/\| \*\*GAP-026B-1 — Access & Cutover Machinery\*\* \| (.*?) \|/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate GAP-026B-1 slice row in IMPLEMENTATION_GAPS.md');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function gap026b2SliceRow(): string
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        if (! preg_match(
            '/\| \*\*GAP-026B-2 — Authority & Presentation Cutover\*\* \| (.*?) \|/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate GAP-026B-2 slice row in IMPLEMENTATION_GAPS.md');
        }

        return $matches[1];
    }
}
