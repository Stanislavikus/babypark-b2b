<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FieldMappingDocumentationContractTest extends TestCase
{
    #[Test]
    public function domain_model_documents_field_mapping_first_persistence_contract(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '#### FieldMapping first persistence contract',
            $content,
        );
        $this->assertStringContainsString('[Resolved — Task 4C-1a, 2026-08-12]', $content);
    }

    #[Test]
    public function contract_uses_field_binding_id_and_external_field_key(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString('`field_binding_id`', $section);
        $this->assertStringContainsString('`external_field_key`', $section);
        $this->assertStringContainsString('Minimum physical schema — `field_mappings`', $section);
    }

    #[Test]
    public function contract_is_direction_neutral_and_not_snapshot_identity(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString('direction-neutral semantic correspondence', $section);
        $this->assertStringContainsString('not an execution plan', $section);
        $this->assertStringContainsString(
            'Do **not** reference `connector_schema_snapshot_fields.id` as persistent mapping',
            $section,
        );
        $this->assertStringContainsString('`snapshot_field_id`', $section);
        $this->assertStringContainsString('Not in the minimum table', $section);
    }

    #[Test]
    public function contract_separates_effective_mappings_from_suggestions(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString('Suggestions are not effective FieldMappings', $section);
        $this->assertMatchesRegularExpression(
            '/must\s+\*\*not\*\*\s+auto-persist as effective mappings/',
            $section,
        );
        $this->assertStringContainsString('Prefill = presentation /', $section);
        $this->assertStringContainsString('confirmed `field_mappings` row = effective configuration state', $section);
    }

    #[Test]
    public function contract_requires_mappings_in_configuration_revision_v2(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString('babypark.sync-configuration-revision.v2', $section);
        $this->assertStringContainsString('"field_mappings"', $section);
        $this->assertStringContainsString('atomically advance `SyncConfiguration.configuration_revision`', $section);
        $this->assertStringContainsString('no-op', $section);
    }

    #[Test]
    public function contract_limits_first_slice_to_products_field_bindings(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString('`data_domain` | `products` only', $section);
        $this->assertStringContainsString('`FieldBinding` only', $section);
        $this->assertStringContainsString('`product`, `product_variant`', $section);
        $this->assertStringContainsString('reject `customer`', $section);
    }

    #[Test]
    public function contract_defers_non_field_binding_domain_targets(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString('pricing-domain, availability-domain, media-relation', $section);
        $this->assertStringContainsString(
            'Do not add polymorphic target columns',
            $section,
        );
    }

    #[Test]
    public function contract_documents_one_to_one_cardinality_inside_sync_configuration(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString(
            'UNIQUE(sync_configuration_id, field_binding_id)',
            $section,
        );
        $this->assertStringContainsString(
            'UNIQUE(sync_configuration_id, external_field_key)',
            $section,
        );
    }

    #[Test]
    public function contract_documents_transitive_delete_protection_and_4c_1b_graceful_handling(): void
    {
        $section = $this->fieldMappingPersistenceContractSection();

        $this->assertStringContainsString(
            '`field_mappings.field_binding_id → field_bindings.id` remains',
            $section,
        );
        $this->assertStringContainsString('`ON DELETE RESTRICT`', $section);
        $this->assertStringContainsString('Direct binding delete blocked', $section);
        $this->assertStringContainsString('Parent definition delete transitively blocked', $section);
        $this->assertStringContainsString('No silent mapping loss', $section);
        $this->assertStringContainsString('Do **not** change', $section);
        $this->assertStringContainsString('`CASCADE` or `nullOnDelete()`', $section);
        $this->assertStringContainsString('Task 4C-1b obligation', $section);
        $this->assertStringContainsString('graceful handling', $section);
    }

    #[Test]
    public function implementation_gaps_records_task_4c_1a_done_and_4c_1b_done(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('**4C-1a** | FieldMapping persistence contract', $content);
        $this->assertStringContainsString('**4C-1b**', $content);
        $this->assertStringContainsString('**4C-1c-0**', $content);
        $this->assertStringContainsString('**4C-1c-1**', $content);
        $this->assertStringContainsString('**4C-1c-2a**', $content);
        $this->assertStringContainsString('**4C-1c-2b**', $content);
        $this->assertStringContainsString('Workspace-scoped authorization foundation', $content);
        $this->assertStringContainsString('Task 4C-1a settled the FieldMapping first persistence contract', $content);
        $this->assertStringContainsString('graceful fail-closed handling when mapped `FieldBinding`', $content);
        $this->assertMatchesRegularExpression('/\*\*4C-1b\*\*.*— Done/', $content);
        $this->assertMatchesRegularExpression('/\*\*4C-1c-0\*\*.*— Done/', $content);
        $this->assertMatchesRegularExpression('/\*\*4C-1c-1\*\*.*— Done/', $content);
        $this->assertStringNotContainsString('4C-1b (not implemented)', $content);
    }

    #[Test]
    public function domain_model_documents_canonical_field_mapping_suggestion_contract(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '#### Canonical FieldMapping suggestion/read-model contract',
            $content,
        );
        $this->assertStringContainsString('[Resolved — Task 4C-1c-0, 2026-08-12]', $content);
    }

    #[Test]
    public function suggestion_contract_preserves_three_layer_boundary(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('canonical platform knowledge', $section);
        $this->assertStringContainsString('account authoritative discovery', $section);
        $this->assertStringContainsString('merchant-confirmed FieldMapping', $section);
        $this->assertStringContainsString('FieldMappingMutationService', $section);
    }

    #[Test]
    public function suggestion_contract_never_auto_persists_suggestions(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('Suggestions are side-effect free', $section);
        $this->assertStringContainsString('insert/update/delete `field_mappings`', $section);
        $this->assertStringContainsString('write suggestion/confidence state anywhere', $section);
        $this->assertStringContainsString('Only explicit confirmation calls the existing `FieldMappingMutationService`', $section);
    }

    #[Test]
    public function suggestion_contract_uses_transient_non_numeric_confidence(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('qualification gate', $section);
        $this->assertStringContainsString('not a numeric score', $section);
        $this->assertStringContainsString('percentage confidence', $section);
        $this->assertStringContainsString('Do **not** introduce', $section);
        $this->assertStringContainsString('anything else → **no** prefill suggestion', $section);
    }

    #[Test]
    public function suggestion_contract_requires_exact_external_field_key_match(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('**exactly equals** an `external_field_key`', $section);
        $this->assertStringContainsString('`sku` | `sku` | eligible evidence', $section);
        $this->assertStringContainsString(
            '`custom_attributes[attribute_code=description].value` | `description` | **not** an automatic high-confidence suggestion',
            $section,
        );
    }

    #[Test]
    public function suggestion_contract_forbids_generic_transport_path_parsing(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('must not** parse connector transport syntax', $section);
        $this->assertStringContainsString('Do **not** strip wrappers', $section);
        $this->assertStringContainsString('Magento custom-attribute paths', $section);
        $this->assertStringContainsString('connector-specific parsing inside generic Sync code', $section);
    }

    #[Test]
    public function suggestion_contract_documents_registry_and_connector_namespace_non_equivalence(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('registry `channel` namespace ≠ `ConnectorDefinition.code`', $section);
        $this->assertStringContainsString('not an assertion that the two namespaces', $section);
        $this->assertStringContainsString('are identical sets', $section);
        $this->assertStringContainsString('Non-normative current-baseline evidence only', $section);
        $this->assertStringContainsString('do not treat as permanent `[Resolved]` invariants', $section);
        $this->assertStringContainsString('no matching registry channel → **no canonical suggestion**', $section);
    }

    #[Test]
    public function suggestion_contract_freezes_canonical_internal_target_resolution(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('G.1 Canonical internal target resolution', $section);
        $this->assertStringContainsString('`field_definition_eligibility = yes`', $section);
        $this->assertStringContainsString('canonical field `status = active`', $section);
        $this->assertStringContainsString('`verification_status = verified`', $section);
        $this->assertStringContainsString('canonical field `scope` must describe', $section);
        $this->assertStringContainsString('`system` or', $section);
        $this->assertStringContainsString('`platform_library`', $section);
        $this->assertStringContainsString('`workspace_id IS NULL`', $section);
        $this->assertStringContainsString('`code = X`', $section);
        $this->assertStringContainsString('actual definition `scope` equals canonical registry `scope`', $section);
        $this->assertStringContainsString('Workspace-custom same-code exclusion', $section);
        $this->assertStringContainsString('`binding_strategy`', $section);
        $this->assertStringContainsString('`product_and_variant_two_bindings`', $section);
        $this->assertStringContainsString('fail closed if the canonical-field → definition → binding', $section);
    }

    #[Test]
    public function suggestion_contract_excludes_ineligible_canonical_fields_from_field_binding_projection(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('rows with `no`', $section);
        $this->assertStringContainsString('**cannot** be projected to a `FieldBinding`', $section);
        $this->assertStringContainsString('pricing-domain', $section);
        $this->assertStringContainsString('connector-only', $section);
    }

    #[Test]
    public function suggestion_contract_enforces_projection_level_suggestion_one_to_one_invariant(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('G.2 Suggestion-set 1:1 invariant', $section);
        $this->assertStringContainsString('one field_binding_id     → at most one suggested external_field_key', $section);
        $this->assertStringContainsString('one external_field_key   → at most one suggested field_binding_id', $section);
        $this->assertStringContainsString('collision among suggestions → no high-confidence suggestion for any colliding candidate', $section);
        $this->assertStringContainsString('Do **not** choose first row', $section);
        $this->assertMatchesRegularExpression('/even when each internal binding\s+individually has exactly one candidate/', $section);
    }

    #[Test]
    public function suggestion_contract_allows_read_model_without_authoritative_discovery(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('Distinguish **mutation validation**', $section);
        $this->assertStringContainsString('confirm/replace with no authoritative discovery → **reject**', $section);
        $this->assertStringContainsString('renderable without discovery', $section);
        $this->assertStringContainsString('at most one** authoritative discovery resolution attempt', $section);
        $this->assertStringContainsString('discovery-unavailable state', $section);
        $this->assertStringContainsString('do **not** call `resolveRequiredSnapshot()` per row', $section);
        $this->assertStringContainsString('Do **not** delete or replace an existing `FieldMapping` merely because discovery', $section);
        $this->assertMatchesRegularExpression('/Do \*\*not\*\* introduce a\s+persisted availability\/status column/', $section);
    }

    #[Test]
    public function suggestion_contract_projects_existing_mappings_when_discovery_unavailable(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('No usable authoritative snapshot', $section);
        $this->assertStringContainsString('no canonical suggestions', $section);
        $this->assertStringContainsString('retain and project unchanged', $section);
        $this->assertStringContainsString('discovery-unavailable / needs-attention state only', $section);
        $this->assertStringContainsString('existing effective mappings remain visible', $section);
    }

    #[Test]
    public function suggestion_contract_ambiguity_produces_no_high_confidence_suggestion(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('ambiguous** → **no**', $section);
        $this->assertMatchesRegularExpression('/\*\*exactly one\*\* resulting\s+semantic candidate/', $section);
        $this->assertStringContainsString('Fail closed to “no suggestion” on ambiguity', $section);
        $this->assertStringContainsString('No arbitrary “latest”', $section);
    }

    #[Test]
    public function suggestion_contract_forbids_runtime_version_guessing(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertMatchesRegularExpression(
            "/Do not guess or persist a connected store's runtime version/s",
            $section,
        );
        $this->assertStringContainsString('Do **not** hardcode `2.4.9-admin-rest`', $section);
        $this->assertMatchesRegularExpression('/knowledge evidence\s+only/', $section);
    }

    #[Test]
    public function suggestion_contract_existing_mapping_wins_over_suggestion(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('Existing mappings beat suggestions', $section);
        $this->assertStringContainsString('effective mapping wins', $section);
        $this->assertStringContainsString('never**', $section);
        $this->assertStringContainsString('replace/prefill over it', $section);
    }

    #[Test]
    public function suggestion_contract_documents_4c_1c_sequencing_slices(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('**4C-1c-0**', $section);
        $this->assertStringContainsString('**4C-1c-1**', $section);
        $this->assertStringContainsString('**4C-1c-2a**', $section);
        $this->assertStringContainsString('**4C-1c-2b**', $section);
        $this->assertStringContainsString('Workspace-scoped authorization foundation', $section);
        $this->assertStringContainsString('DB/migration scope', $section);
        $this->assertStringContainsString('Layer B mapping UI', $section);
        $this->assertStringContainsString('explicit confirmation through 4C-1b service', $section);
    }

    #[Test]
    public function suggestion_contract_places_mapping_ui_in_layer_b(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('Mapping is **Layer B**', $section);
        $this->assertStringContainsString('concept-first matrix', $section);
        $this->assertMatchesRegularExpression('/merchant confirmation\s+is still \*\*explicit\*\*/', $section);
        $this->assertStringContainsString('do **not** embed mapping controls into the current **Інтеграції**', $section);
        $this->assertStringContainsString('UI placement (4C-1c-2b)', $section);
    }

    #[Test]
    public function domain_model_documents_workspace_access_authorization_contract(): void
    {
        $section = $this->workspaceAccessAuthorizationSection();

        $this->assertStringContainsString('Atomic `Permission` is the authorization source of truth', $section);
        $this->assertMatchesRegularExpression('/have \*\*no\*\*\s+authorization semantics/', $section);
        $this->assertMatchesRegularExpression('/must \*\*not\*\* grant a\s+capability merely because `User\.role === Merchandiser`/', $section);
        $this->assertStringContainsString('workspace-owned, merchant-configurable **named bundle of atomic permissions**', $section);
        $this->assertStringContainsString('onboarding/default templates only', $section);
        $this->assertMatchesRegularExpression('/receive \*\*multiple\*\* workspace roles/', $section);
        $this->assertStringContainsString('evaluated for **User × Workspace**', $section);
        $this->assertStringContainsString('Future `WorkspaceUser`', $section);
        $this->assertMatchesRegularExpression('/\*\*Cross-workspace permission\s+leakage is a critical failure/', $section);
    }

    #[Test]
    public function access_contract_freezes_additive_permission_composition_model(): void
    {
        $section = $this->workspaceAccessAuthorizationSection();

        $this->assertStringContainsString('Atomic permissions are the authorization source of truth.', $section);
        $this->assertStringContainsString('Workspace roles/access profiles are merchant-owned named bundles of those atomic permissions.', $section);
        $this->assertStringContainsString('Platform-provided role names are onboarding templates only and have no authorization semantics.', $section);
        $this->assertStringContainsString('assign multiple roles to one membership.', $section);
        $this->assertStringContainsString('union(all assigned workspace-role permissions).', $section);
        $this->assertStringContainsString('Absence of a permission means deny.', $section);
        $this->assertStringContainsString('no explicit deny/mute precedence in the first RBAC foundation.', $section);
    }

    #[Test]
    public function access_contract_defers_direct_user_overrides_and_muting(): void
    {
        $section = $this->workspaceAccessAuthorizationSection();

        $this->assertStringContainsString('**Deferred — not part of the first workspace RBAC foundation:**', $section);
        $this->assertStringContainsString('does **not** implement direct per-user permission overrides', $section);
        $this->assertStringContainsString('negative permissions, or permission muting', $section);
        $this->assertStringContainsString('Salesforce Permission Set Group muting', $section);
        $this->assertStringContainsString('accepted MVP tradeoff', $section);
        $this->assertStringContainsString('Exact allow/deny precedence is **not** resolved now', $section);
    }

    #[Test]
    public function access_contract_documents_merchant_roles_ux_without_spatie_terminology(): void
    {
        $section = $this->workspaceAccessAuthorizationSection();

        $this->assertStringContainsString('Roles / Access profiles', $section);
        $this->assertMatchesRegularExpression('/\*\*one or more\*\* roles for that workspace/', $section);
        $this->assertStringContainsString('business-owned labels, not predefined job taxonomy', $section);
        $this->assertMatchesRegularExpression('/assigning an \*\*additional\*\*\s+role/', $section);
        $this->assertStringContainsString('Do **not** expose Spatie', $section);
    }

    #[Test]
    public function access_contract_documents_verified_authorization_mismatch(): void
    {
        $section = $this->workspaceAccessAuthorizationSection();

        $this->assertStringContainsString('`User.role`', $section);
        $this->assertStringContainsString('`ConnectorAccountPolicy` fixed-role checks', $section);
        $this->assertStringContainsString('`WorkspaceUser` membership is **not** implemented', $section);
        $this->assertStringContainsString("'teams' => false", $section);
        $this->assertStringContainsString('**GAP-026**', $section);
        $this->assertStringContainsString('cross-reference **GAP-004**', $section);
    }

    #[Test]
    public function domain_model_freezes_sync_mapping_permissions_separate_from_connector_accounts(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('### Sync mapping permissions (Resolved — Task 4C-1c-2a', $content);
        $this->assertStringContainsString('`view_sync_mappings`', $content);
        $this->assertStringContainsString('`manage_sync_mappings`', $content);
        $this->assertMatchesRegularExpression('/are \*\*independent\*\* from\s+`manage_connector_accounts`/', $content);
        $this->assertMatchesRegularExpression('/\*\*never\*\* grants credential, settings, base\s+URL, or auth-profile access/', $content);
        $this->assertMatchesRegularExpression('/does \*\*not\*\* automatically grant mapping\s+permissions/', $content);
        $this->assertMatchesRegularExpression('/including \*\*Merchandiser\*\* — is normatively\s+entitled/', $content);
    }

    #[Test]
    public function implementation_gaps_corrects_gap_025_and_records_gap_026(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('`SyncConfiguration` persistence and domain write path — **implemented**', $content);
        $this->assertStringContainsString('`FieldMapping` persistence, manual confirmation', $content);
        $this->assertStringContainsString('Canonical suggestion/read-model provider — **implemented**', $content);
        $this->assertStringContainsString('Do not claim `SyncConfiguration` or `FieldMapping` are unimplemented', $content);
        $this->assertStringContainsString('## GAP-026 — Workspace-scoped RBAC foundation not implemented', $content);
        $this->assertMatchesRegularExpression("/'teams' => false/", $content);
        $this->assertStringContainsString('blocks 4C-1c-2b mapping UI', $content);
        $this->assertStringContainsString('**Cross-reference:** **GAP-004**', $content);
    }

    #[Test]
    public function suggestion_contract_uses_canonical_registry_reader_only(): void
    {
        $section = $this->fieldMappingSuggestionContractSection();

        $this->assertStringContainsString('`CanonicalRegistryReader` is the existing read-only CSV access path', $section);
        $this->assertMatchesRegularExpression('/Do \*\*not\*\*\s+create a second registry\/loader/', $section);
    }

    /**
     * @return non-empty-string
     */
    private function workspaceAccessAuthorizationSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/### Workspace access model and authorization \(Resolved — Task 4C-1c-2a, 2026-08-13\)\n\n(.*?)(?=\n### Sync mapping permissions)/s',
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
    private function fieldMappingSuggestionContractSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/#### Canonical FieldMapping suggestion\/read-model contract\n\[Resolved — Task 4C-1c-0, 2026-08-12\]\n\n(.*?)(?=\n### Canonical mapping registry role)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Canonical FieldMapping suggestion/read-model contract section in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function fieldMappingPersistenceContractSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/#### FieldMapping first persistence contract\n\[Resolved — Task 4C-1a, 2026-08-12\]\n\n(.*?)(?=\n#### Canonical FieldMapping suggestion\/read-model contract)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate FieldMapping first persistence contract section in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }
}
