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

        $this->assertStringContainsString('GAP-026A — Physical RBAC Foundation', $content);
        $this->assertStringContainsString('GAP-026B — Narrow workspace-authorization cutover', $content);
        $this->assertStringContainsString('4C-1c-2b Mapping UI becomes unblocked', $content);
        $this->assertStringContainsString('physical architecture frozen (GAP-026-0)', $content);
        $this->assertStringContainsString('implementation not', $content);
        $this->assertStringContainsString('started. Closure requires 026A foundation', $content);
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
    }

    #[Test]
    public function project_documentation_map_records_workspace_rbac_physical_decision(): void
    {
        $content = File::get(base_path('docs/Project_Documentation_Map.md'));

        $this->assertStringContainsString('Workspace RBAC physical model', $content);
        $this->assertStringContainsString('WorkspaceUser-centric custom RBAC', $content);
        $this->assertStringContainsString('anti-lockout serialized on `Workspace` row', $content);
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
