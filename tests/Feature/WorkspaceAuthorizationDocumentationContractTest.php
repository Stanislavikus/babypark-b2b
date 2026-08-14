<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkspaceAuthorizationDocumentationContractTest extends TestCase
{
    #[Test]
    public function domain_model_documents_workspace_access_model_resolved_decision(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '### Workspace access model and authorization (Resolved — Task 4C-1c-2a, 2026-08-13)',
            $content,
        );
    }

    #[Test]
    public function domain_model_documents_rebaselined_connector_account_authorization(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '### ConnectorAccount authorization (Resolved — rebaselined Task 4C-1c-2a, 2026-08-13)',
            $content,
        );
        $this->assertStringContainsString('ConnectorAccount capability evaluation (frozen):', $content);
    }

    #[Test]
    public function contract_uses_atomic_permissions_not_job_titles(): void
    {
        $section = $this->workspaceAccessAuthorizationSection();

        $this->assertStringContainsString('Atomic permissions', $section);
        $this->assertStringContainsString('authorization source of truth', $section);
        $this->assertStringContainsString('Job-title / role names', $section);
        $this->assertStringContainsString('have **no authorization semantics**', $section);
        $this->assertStringContainsString(
            'must **not** grant a capability merely because',
            $section,
        );
        $this->assertStringContainsString('User.role === Merchandiser|Admin|Director', $section);
    }

    #[Test]
    public function contract_documents_all_seven_frozen_permission_names(): void
    {
        $section = $this->workspaceAccessAuthorizationSection();

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
    }

    #[Test]
    public function contract_documents_permission_independence_rules(): void
    {
        $section = $this->workspaceAccessAuthorizationSection();

        $this->assertStringContainsString(
            '`manage_connector_accounts` **does not** imply `view_sync_mappings`',
            $section,
        );
        $this->assertStringContainsString(
            '`view_sync_mappings` / `manage_sync_mappings` **do not** imply connector',
            $section,
        );
        $this->assertStringContainsString(
            '`manage_workspace_access` **does not** automatically imply connector',
            $section,
        );
        $this->assertStringContainsString(
            'No job-title name may substitute for any permission above.',
            $section,
        );
    }

    #[Test]
    public function contract_documents_connector_account_capability_implications(): void
    {
        $section = $this->connectorAccountAuthorizationSection();

        $this->assertStringContainsString('`view_connector_accounts` **OR** `run_connector_discovery` **OR** `manage_connector_accounts`', $section);
        $this->assertStringContainsString('Manual discovery trigger', $section);
        $this->assertStringContainsString('`run_connector_discovery` **OR** `manage_connector_accounts`', $section);
        $this->assertStringContainsString('Connection check | `manage_connector_accounts`', $section);
        $this->assertStringContainsString('Create / settings / credential mutation / disable / archive | `manage_connector_accounts`', $section);
    }

    #[Test]
    public function contract_documents_manage_workspace_access_without_business_permission_implication(): void
    {
        $section = $this->workspaceAccessAuthorizationSection();

        $this->assertStringContainsString('effective **`manage_workspace_access`** may manage', $section);
        $this->assertStringContainsString(
            'product, price, order, or other business permissions',
            $section,
        );
    }

    #[Test]
    public function contract_documents_anti_lockout_invariant(): void
    {
        $section = $this->workspaceAccessAuthorizationSection();

        $this->assertStringContainsString('**Anti-lockout invariant (Resolved — security)**', $section);
        $this->assertStringContainsString('at least one active membership with effective', $section);
        $this->assertStringContainsString('`manage_workspace_access`', $section);
        $this->assertStringContainsString('Initial workspace creation/bootstrap must establish', $section);
        $this->assertStringContainsString('enforced transactionally in the future write service', $section);
        $this->assertStringContainsString('is **not** resolved in 4C-1c-2a', $section);
        $this->assertStringContainsString('must **not** silently bypass tenant authorization', $section);
    }

    #[Test]
    public function contract_documents_composition_model_verbatim(): void
    {
        $section = $this->workspaceAccessAuthorizationSection();

        $this->assertStringContainsString('**Access-model composition:**', $section);
        $this->assertStringContainsString(
            'Workspace roles/access profiles are merchant-owned named bundles of those atomic permissions.',
            $section,
        );
        $this->assertStringContainsString(
            'Platform-provided role names are onboarding templates only and have no authorization semantics.',
            $section,
        );
        $this->assertStringContainsString(
            'Effective permissions in the first implementation are additive: union(all assigned workspace-role permissions).',
            $section,
        );
        $this->assertStringContainsString('Absence of a permission means deny.', $section);
        $this->assertStringContainsString(
            'There is no explicit deny/mute precedence in the first RBAC foundation.',
            $section,
        );
    }

    #[Test]
    public function contract_defers_direct_user_overrides_and_muting(): void
    {
        $section = $this->workspaceAccessAuthorizationSection();

        $this->assertStringContainsString('**Direct per-user permission overrides** are **deferred**', $section);
        $this->assertStringContainsString('**Deferred**', $section);
        $this->assertStringContainsString('negative permissions, or permission muting', $section);
        $this->assertStringContainsString('Salesforce Permission Set Group muting', $section);
        $this->assertStringContainsString('Exact allow/deny precedence is **NOT** resolved now', $section);
    }

    #[Test]
    public function contract_documents_manage_workspace_access_for_role_profile_administration(): void
    {
        $section = $this->workspaceAccessAuthorizationSection();

        $this->assertStringContainsString(
            'A workspace membership authorized with effective **`manage_workspace_access`** may create/name/manage workspace **Roles / Access profiles**',
            $section,
        );
        $this->assertStringNotContainsString('Workspace administrators may **name roles freely**', $section);
    }

    #[Test]
    public function domain_model_has_no_normative_job_title_connector_grants(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringNotContainsString(
            'Credential view/edit is limited to Admin, Director',
            $content,
        );
        $this->assertStringNotContainsString(
            'Merchandiser may run **manual** discovery',
            $content,
        );
        $this->assertStringNotContainsString(
            'administrative-role action only',
            $content,
        );
        $this->assertStringNotContainsString(
            'Historical MVP wording below (owner/manager/viewer examples)',
            $content,
        );
    }

    #[Test]
    public function ui_design_system_delegates_layer_ab_authorization_to_workspace_contract(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString(
            'Workspace merchant users when authorized by workspace permissions',
            $content,
        );
        $this->assertStringContainsString(
            'Workspace access model and authorization (Resolved — Task 4C-1c-2a)',
            $content,
        );
        $this->assertStringContainsString(
            'do **not** authorize anything by themselves',
            $content,
        );
        $this->assertStringNotContainsString(
            'Existing `ConnectorAccountPolicy` and role permissions remain authoritative inside Layers A/B',
            $content,
        );
        $this->assertStringNotContainsString(
            'Merchandiser Layer B eligibility does not imply credential management',
            $content,
        );
    }

    #[Test]
    public function connector_ux_contract_delegates_authorization_to_workspace_access_contract(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertStringContainsString(
            'Workspace access model and authorization (Resolved — Task 4C-1c-2a)',
            $content,
        );
        $this->assertStringContainsString(
            'ConnectorAccount authorization (Resolved — rebaselined Task 4C-1c-2a)',
            $content,
        );
        $this->assertStringContainsString(
            'unavailable to **all** workspace merchant role/access profiles',
            $content,
        );
        $this->assertStringNotContainsString(
            'Merchandiser access to connection setup',
            $content,
        );
        $this->assertStringNotContainsString(
            'Existing policies/permissions remain authoritative',
            $content,
        );
    }

    #[Test]
    public function gap_026_records_frozen_vocabulary_anti_lockout_and_unresolved_mechanics(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString(
            '## GAP-026 — Workspace-scoped RBAC foundation partially implemented; authority cutover pending',
            $content,
        );
        $this->assertStringNotContainsString(
            '## GAP-026 — Workspace-scoped RBAC foundation not implemented',
            $content,
        );
        $this->assertStringContainsString('**Frozen minimum permission vocabulary (implemented in GAP-026A-1):**', $content);
        $this->assertStringContainsString('`view_connector_accounts`', $content);
        $this->assertStringContainsString('`run_connector_discovery`', $content);
        $this->assertStringContainsString('`manage_workspace_access`', $content);
        $this->assertStringContainsString('`manage_workspace_tax_settings`', $content);
        $this->assertStringContainsString('Physical persistence is **resolved** in GAP-026-0', $content);
        $this->assertStringContainsString('GAP-026A-1 — Schema, catalogue & explicit read authorization', $content);
        $this->assertStringContainsString('GAP-026A-2 — Preflight/backfill machinery & anti-lockout coordinator', $content);
        $this->assertStringContainsString('GAP-026B-0 — Workspace RBAC authority cutover contract', $content);
        $this->assertStringContainsString('GAP-026B-1 — Access & Cutover Machinery', $content);
        $this->assertStringContainsString('GAP-026B-2 — Authority & Presentation Cutover', $content);
        $this->assertStringContainsString('4C-1c-2b Mapping UI remains blocked until production cutover completes successfully', $content);
        $this->assertStringContainsString('physical architecture frozen (GAP-026-0)', $content);
        $this->assertStringContainsString('Open / activation pending', $content);
        $this->assertStringContainsString('GAP-026A (overall)** | **Done**', $content);
        $this->assertStringContainsString('GAP-026B-1 **Done**', $content);
        $this->assertStringContainsString('Part 2 merchant Access/Roles UI', $content);
        $this->assertStringContainsString('workspace-rbac:cutover-check', $content);
    }

    #[Test]
    public function gap_027_records_platform_wide_admin_resource_rbac_scope(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('## GAP-027 — Platform-wide admin Resource RBAC', $content);
        $this->assertStringContainsString('`strictAuthorization()`', $content);
        $this->assertStringContainsString('membership-based `/admin` admission', $content);
        $this->assertStringContainsString('new staff membership onboarding', $content);
        $this->assertStringContainsString('existing-memberships-only limitation', $content);
        $this->assertStringContainsString('receives **no** `WorkspaceUser` automatically', $content);
        $this->assertStringContainsString('do not broaden `canAccessPanel()` as a workaround', $content);
    }

    #[Test]
    public function gap_025_sync_backend_truth_remains_corrected(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('**Implemented sync-domain backend (verified on `develop`; not a GAP-025 UX claim):**', $content);
        $this->assertStringContainsString('`SyncConfiguration` persistence and domain write path (Task 4C-0).', $content);
        $this->assertStringContainsString('Layer B mapping UI still missing', $content);
        $this->assertStringNotContainsString(
            'Sync Domain persistence/runtime (`SyncConfiguration`, `FieldMapping`,',
            $content,
        );
    }

    /**
     * @return non-empty-string
     */
    private function workspaceAccessAuthorizationSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/### Workspace access model and authorization \(Resolved — Task 4C-1c-2a, 2026-08-13\)\n\n(.*?)(?=\n## Product Catalogue Context)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Workspace access model and authorization section in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function connectorAccountAuthorizationSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/### ConnectorAccount authorization \(Resolved — rebaselined Task 4C-1c-2a, 2026-08-13\)\n\n(.*?)(?=\n### Connection-check capability and error mapping)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate ConnectorAccount authorization section in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }
}
