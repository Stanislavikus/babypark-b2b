<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkspaceRbacPhysicalArchitectureDocumentationContractTest extends TestCase
{
    #[Test]
    public function domain_model_documents_workspace_rbac_physical_architecture_resolved(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '### Workspace RBAC physical architecture [Resolved — GAP-026-0, 2026-08-13]',
            $content,
        );
    }

    #[Test]
    public function contract_freezes_custom_workspace_user_centric_rbac_not_spatie_teams(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('Freeze custom workspace RBAC, not Spatie Teams.', $section);
        $this->assertStringContainsString(
            'Spatie Teams is not the authoritative tenant-scoping mechanism.',
            $section,
        );
    }

    #[Test]
    public function contract_names_five_physical_tables_and_workspace_user_assignment_principal(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString(
            '`WorkspaceUser` is the authoritative workspace-membership and role-assignment principal.',
            $section,
        );
        $this->assertStringContainsString('A global `User` must never receive a workspace role directly.', $section);

        foreach ([
            'workspace_users',
            'workspace_roles',
            'workspace_permissions',
            'workspace_user_roles',
            'workspace_role_permissions',
        ] as $table) {
            $this->assertStringContainsString($table, $section);
        }
    }

    #[Test]
    public function contract_freezes_workspace_users_minimum_fields_without_invitation_lifecycle(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('`id`', $section);
        $this->assertStringContainsString('`workspace_id`', $section);
        $this->assertStringContainsString('`user_id`', $section);
        $this->assertStringContainsString('`is_active`', $section);
        $this->assertStringContainsString('UNIQUE (workspace_id, user_id)', $section);
        $this->assertStringContainsString('UNIQUE (id, workspace_id)', $section);
        $this->assertStringContainsString('`deleted_at`', $section);
        $this->assertStringContainsString('`invited_at`', $section);
        $this->assertStringContainsString('`activated_at`', $section);
        $this->assertStringContainsString('`deactivated_at`', $section);
        $this->assertStringContainsString('Invitation/deactivation history/soft-delete lifecycle is not', $section);
    }

    #[Test]
    public function contract_documents_active_membership_requires_both_membership_and_user_active(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('workspace_users.is_active = true', $section);
        $this->assertStringContainsString('users.is_active = true', $section);
        $this->assertStringContainsString('`vacation_until` is not authorization state.', $section);
        $this->assertStringContainsString('Do not add `workspaces.is_active` in GAP-026.', $section);
    }

    #[Test]
    public function contract_documents_explicit_workspace_authorization_boundary(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('interface WorkspaceAuthorization', $section);
        $this->assertStringContainsString('`Workspace` is a mandatory argument.', $section);
        $this->assertStringContainsString(
            'No authorization API overload may silently derive its `Workspace` from',
            $section,
        );
        $this->assertStringContainsString(
            '`WorkspaceContext` may continue to exist for data/UI scoping, but it is not the',
            $section,
        );
        $this->assertStringContainsString('authorization authority', $section);
    }

    #[Test]
    public function architecture_principles_require_explicit_workspace_for_authorization(): void
    {
        $content = File::get(base_path('docs/04-ARCHITECTURE_PRINCIPLES.md'));

        $this->assertStringContainsString(
            'Workspace-scoped authorization MUST receive the target `Workspace` explicitly.',
            $content,
        );
        $this->assertStringContainsString(
            'MUST NOT be the security authority for permission',
            $content,
        );
        $this->assertStringContainsString(
            'Does workspace-scoped authorization receive the target `Workspace` explicitly',
            $content,
        );
    }

    #[Test]
    public function contract_documents_seven_permissions_including_tax_settings(): void
    {
        $section = $this->physicalArchitectureSection();

        foreach ([
            'view_connector_accounts',
            'run_connector_discovery',
            'manage_connector_accounts',
            'view_sync_mappings',
            'manage_sync_mappings',
            'manage_workspace_access',
            'manage_workspace_tax_settings',
        ] as $permission) {
            $this->assertStringContainsString('`'.$permission.'`', $section);
        }

        $this->assertStringContainsString('Amend the frozen minimum catalogue from six to seven', $section);
    }

    #[Test]
    public function contract_documents_mapping_permissions_assigned_to_nobody_in_backfill(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('| `view_sync_mappings` | assigned to nobody |', $section);
        $this->assertStringContainsString('| `manage_sync_mappings` | assigned to nobody |', $section);
        $this->assertStringContainsString('do **not** grant new Mapping capability', $section);
    }

    #[Test]
    public function contract_documents_legacy_membership_backfill_keeps_workspace_users_active(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('`is_default = true`', $section);
        $this->assertStringContainsString('Never hardcode the UUID.', $section);
        $this->assertStringContainsString('`customer_id IS NULL`', $section);
        $this->assertStringContainsString('`workspace_users.is_active = true` for the legacy', $section);
        $this->assertStringContainsString('regardless of `users.is_active`', $section);
    }

    #[Test]
    public function contract_freezes_workspace_roles_template_key_uniqueness_and_nullable_custom_roles(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('UNIQUE (workspace_id, template_key)', $section);
        $this->assertStringContainsString('non-null `template_key` is the stable template/bootstrap identity', $section);
        $this->assertStringContainsString('custom merchant-created roles may have NULL', $section);
        $this->assertStringContainsString('multiple NULL values remain valid', $section);
        $this->assertStringContainsString('merchant rename of `name` never changes `template_key`', $section);
        $this->assertStringContainsString('resolve platform template roles by stable key, not', $section);
        $this->assertStringContainsString('carries no authorization semantics', $section);
    }

    #[Test]
    public function contract_freezes_parent_foreign_keys_with_restrict_on_delete(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString(
            '`workspace_users.workspace_id` → `workspaces.id` ON DELETE RESTRICT',
            $section,
        );
        $this->assertStringContainsString(
            '`workspace_users.user_id` → `users.id` ON DELETE RESTRICT',
            $section,
        );
        $this->assertStringContainsString(
            '`workspace_roles.workspace_id` → `workspaces.id` ON DELETE RESTRICT',
            $section,
        );
        $this->assertStringContainsString('must not silently CASCADE workspace deletion', $section);
        $this->assertStringContainsString('Workspace deletion lifecycle remains outside', $section);
    }

    #[Test]
    public function contract_documents_026a_does_not_execute_production_legacy_backfill(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('026A / 026B staging — legacy backfill execution', $section);
        $this->assertStringContainsString('production execution', $section);
        $this->assertStringContainsString('gated to GAP-026B — not GAP-026A', $section);
        $this->assertStringContainsString('026A foundation scope (no production legacy assignment)', $section);
        $this->assertStringContainsString(
            'It **MUST NOT** materialize `WorkspaceUser` / `WorkspaceRole` /',
            $section,
        );
        $this->assertStringContainsString(
            'legacy production assignments for existing `Users` as part of',
            $section,
        );
        $this->assertStringContainsString('026A activation', $section);
        $this->assertStringContainsString(
            'must **not** claim target workspace RBAC is already populated or',
            $section,
        );
        $this->assertStringContainsString('authoritative', $section);
    }

    #[Test]
    public function contract_documents_026a_may_implement_backfill_machinery_without_production_execution(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('026A may implement and test:', $section);
        $this->assertStringContainsString('legacy-state preflight service', $section);
        $this->assertStringContainsString('deterministic/idempotent backfill service', $section);
        $this->assertStringContainsString('template-role construction logic', $section);
        $this->assertStringContainsString('anti-lockout coordinator', $section);
        $this->assertStringContainsString(
            'not** executed against production legacy users as part of',
            $section,
        );
        $this->assertStringContainsString('preflight/backfill', $section);
        $this->assertStringContainsString('machinery', $section);
        $this->assertStringContainsString('production legacy', $section);
        $this->assertStringContainsString('`WorkspaceUser` / role assignments are **not** materialized', $section);
    }

    #[Test]
    public function contract_documents_global_permission_catalogue_may_be_seeded_in_026a(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString(
            'The global `workspace_permissions` catalogue may be seeded in 026A because it',
            $section,
        );
        $this->assertStringContainsString('has no `User` / workspace assignment authority by itself', $section);
    }

    #[Test]
    public function contract_documents_026b_cutover_gate_frozen_ordering(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('026B authorization cutover gate (frozen ordering)', $section);
        $this->assertStringContainsString('1. run Spatie assignment preflight', $section);
        $this->assertStringContainsString('2. run legacy workspace/Admin preflight', $section);
        $this->assertStringContainsString(
            '3. execute deterministic/idempotent legacy backfill from **current** legacy state',
            $section,
        );
        $this->assertStringContainsString('4. perform fresh anti-lockout validation', $section);
        $this->assertStringContainsString(
            '5. only if all four succeed may workspace-permission authorization become',
            $section,
        );
        $this->assertStringContainsString('authoritative', $section);
        $this->assertStringContainsString('Spatie assignment deployment preflight (026B step 1)', $section);
        $this->assertStringContainsString('Automatic backfill deployment preflight (fail-closed — 026B step 2)', $section);
        $this->assertStringContainsString('026B legacy backfill must resolve', $section);
    }

    #[Test]
    public function contract_documents_failure_means_no_partial_cutover(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('Failure at any step: STOP', $section);
        $this->assertStringContainsString('no permission-policy cutover', $section);
        $this->assertStringContainsString('no Access/Roles', $section);
        $this->assertStringContainsString('mutation activation', $section);
        $this->assertStringContainsString('no Mapping authorization activation', $section);
        $this->assertStringContainsString('Do not silently fall', $section);
        $this->assertStringContainsString('back to partial RBAC', $section);
    }

    #[Test]
    public function contract_documents_user_lifecycle_protection_in_same_026b_cutover(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('Legacy User lifecycle compatibility', $section);
        $this->assertStringContainsString('Current `User` lifecycle can still:', $section);
        $this->assertStringContainsString('hard-delete `Users` (via `UserResource`)', $section);
        $this->assertStringContainsString(
            'GAP-026B must bring the necessary `User` lifecycle integrity protection',
            $section,
        );
        $this->assertStringContainsString('**no later than** the same cutover', $section);
        $this->assertStringContainsString('materialized and made authoritative', $section);
        $this->assertStringContainsString('Do not weaken or remove the RESTRICT FK', $section);
        $this->assertStringContainsString('`User` lifecycle protection in the same', $section);
        $this->assertStringContainsString('cutover window', $section);
    }

    #[Test]
    public function contract_requires_fail_closed_legacy_backfill_preflight(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('Automatic backfill deployment preflight (fail-closed — 026B step 2)', $section);
        $this->assertStringContainsString('exactly one row exists in `workspaces`', $section);
        $this->assertStringContainsString('exactly-one row with `is_default = true`', $section);
        $this->assertStringContainsString('`role IN (Admin, Director)`', $section);
        $this->assertStringContainsString('inactive Admin/Director does not satisfy active-membership semantics', $section);
        $this->assertStringContainsString('privilege escalation', $section);
        $this->assertStringContainsString('do not infer', $section);
        $this->assertStringContainsString('memberships', $section);
        $this->assertStringContainsString('do not auto-promote a different legacy role', $section);
        $this->assertStringContainsString('do not reactivate', $section);
        $this->assertStringContainsString('do not assign all users to every workspace', $section);
    }

    #[Test]
    public function contract_materializes_backfill_through_workspace_roles_not_direct_user_permissions(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('no direct membership-permission table', $section);
        $this->assertStringContainsString('seeded/bootstrap `WorkspaceRole` bundle(s)', $section);
        $this->assertStringContainsString('assigned to the', $section);
        $this->assertStringContainsString('relevant `WorkspaceUser`', $section);
        $this->assertStringContainsString('No direct `User` / `WorkspaceUser` permission grant', $section);
        $this->assertStringContainsString('Merchant-facing role `name` is not authorization identity', $section);
        $this->assertStringContainsString('`template_key` is the bootstrap identity', $section);
        $this->assertStringContainsString('Do not invent', $section);
        $this->assertStringContainsString('per-user overrides', $section);
    }

    #[Test]
    public function contract_documents_composite_fk_same_workspace_guards_and_restrict_deletes(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('Membership from workspace A', $section);
        $this->assertStringContainsString('structurally unrepresentable', $section);
        $this->assertStringContainsString('RESTRICT / equivalent guarded deletion semantics', $section);
        $this->assertStringContainsString('`users` → `workspace_users`', $section);
        $this->assertStringContainsString('`workspace_users` → `workspace_user_roles`', $section);
        $this->assertStringContainsString('`workspace_roles` → `workspace_user_roles`', $section);
        $this->assertStringContainsString('`workspace_roles` → `workspace_role_permissions`', $section);
        $this->assertStringContainsString('`workspace_permissions` → `workspace_role_permissions`', $section);
    }

    #[Test]
    public function contract_documents_anti_lockout_workspace_row_mutex_and_multi_workspace_lock_order(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('SELECT workspace', $section);
        $this->assertStringContainsString('FOR UPDATE', $section);
        $this->assertStringContainsString('post-mutation effective-permission query', $section);
        $this->assertStringContainsString('Lock the `Workspace` row, not merely the touched membership/role rows', $section);
        $this->assertStringContainsString('deterministic `workspace_id` order', $section);
        $this->assertStringContainsString('must ultimately be verified on MySQL 8', $section);
    }

    #[Test]
    public function contract_documents_spatie_preflight_and_deferred_removal(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('`roles`', $section);
        $this->assertStringContainsString('`model_has_roles`', $section);
        $this->assertStringContainsString('`model_has_permissions`', $section);
        $this->assertStringContainsString('`role_has_permissions`', $section);
        $this->assertStringContainsString('If unexpected assignment rows exist: STOP', $section);
        $this->assertStringContainsString('Do not remove Spatie package/tables in GAP-026A or GAP-026B', $section);
    }

    #[Test]
    public function contract_documents_platform_plane_and_cabinet_separate_from_workspace_rbac(): void
    {
        $section = $this->physicalArchitectureSection();

        $this->assertStringContainsString('`PlatformAdminAuthorization` remains outside GAP-026 workspace RBAC.', $section);
        $this->assertStringContainsString('/cabinet` is outside GAP-026.', $section);
        $this->assertStringContainsString('authenticated principal is `Customer`', $section);
    }

    #[Test]
    public function gap_026_documents_026a_026b_split_and_remains_open(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('GAP-026A-1 — Schema, catalogue & explicit read authorization', $content);
        $this->assertStringContainsString('GAP-026B-0 — Workspace RBAC authority cutover contract', $content);
        $this->assertStringContainsString('GAP-026B-1 — Access & Cutover Machinery', $content);
        $this->assertStringContainsString('GAP-026B-2 — Authority & Presentation Cutover', $content);
        $this->assertStringContainsString('4C-1c-2b remains blocked until GAP-026B-2', $content);
        $this->assertStringContainsString('physical architecture frozen (GAP-026-0)', $content);
        $this->assertStringContainsString('Open / partial', $content);
        $this->assertStringContainsString('GAP-026A (overall)** | **Done**', $content);
        $this->assertStringContainsString('GAP-026B-1 Part 1 runtime core **partially implemented**', $content);
        $this->assertStringContainsString('workspace-rbac:cutover-check', $content);
    }

    #[Test]
    public function gap_027_exists_and_owns_whole_admin_rbac(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('## GAP-027 — Platform-wide admin Resource RBAC', $content);
        $this->assertStringContainsString('`strictAuthorization()`', $content);
        $this->assertStringContainsString('membership-based `/admin` admission', $content);
        $this->assertStringContainsString('do not broaden `canAccessPanel()` as a workaround', $content);
    }

    #[Test]
    public function ui_design_system_defers_access_screen_to_026b(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('Workspace access / Roles UI sequencing (Resolved — GAP-026-0', $content);
        $this->assertStringContainsString('no merchant-facing Access/Roles screen is required', $content);
        $this->assertStringContainsString('introduces the merchant-facing Access surface', $content);
        $this->assertStringContainsString('Користувачі', $content);
        $this->assertStringContainsString('Профілі доступу', $content);
        $this->assertStringContainsString('Never expose Spatie', $content);
        $this->assertStringContainsString('026B Access / Roles scope (Resolved — GAP-026B-0', $content);
    }

    #[Test]
    public function project_documentation_map_records_workspace_rbac_physical_decision(): void
    {
        $content = File::get(base_path('docs/Project_Documentation_Map.md'));

        $this->assertStringContainsString('Workspace RBAC physical model', $content);
        $this->assertStringContainsString('WorkspaceUser-centric custom RBAC', $content);
        $this->assertStringContainsString('anti-lockout serialized on `Workspace` row', $content);
        $this->assertStringContainsString('Workspace RBAC authority cutover (GAP-026B-0)', $content);
        $this->assertStringContainsString('existing-memberships-only Access management', $content);
    }

    /**
     * @return non-empty-string
     */
    private function physicalArchitectureSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/### Workspace RBAC physical architecture \[Resolved — GAP-026-0, 2026-08-13\]\n\n(.*?)(?=\n## Product Catalogue Context)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Workspace RBAC physical architecture section in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }
}
