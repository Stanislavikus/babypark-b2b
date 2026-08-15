<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards authoritative Connector/RBAC docs against stale pre-cutover statements
 * coexisting with GAP-026B production-activated status (2026-08-14).
 */
class Gap026b2DocumentationTruthSyncContractTest extends TestCase
{
    #[Test]
    public function implementation_gaps_records_gap_026b_2_as_production_activated(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString(
            '| **GAP-026B-2 — Authority & Presentation Cutover** | **Done / production-activated (2026-08-14).**',
            $content,
        );
        $this->assertStringContainsString(
            '| **GAP-026B (overall)** | **Done** — production cutover completed 2026-08-14 on Babypark pilot.',
            $content,
        );
    }

    #[Test]
    public function authoritative_docs_do_not_claim_gap_026b_production_execute_is_still_pending(): void
    {
        $files = [
            'docs/IMPLEMENTATION_GAPS.md',
            'docs/03-DOMAIN_MODEL.md',
            'docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md',
            'DEPLOY.md',
        ];

        $forbiddenPhrases = [
            'production EXECUTE not yet performed',
            'Open / activation pending',
            'activation remains pending',
            'blocked until production cutover completes successfully',
            'blocked until GAP-026B',
            'merchant shipping blocked until production EXECUTE',
            'remains blocked until successful production maintenance-window',
        ];

        foreach ($files as $path) {
            $content = File::get(base_path($path));

            foreach ($forbiddenPhrases as $phrase) {
                $this->assertStringNotContainsString(
                    $phrase,
                    $content,
                    "Stale pre-cutover phrase [{$phrase}] found in {$path}",
                );
            }
        }
    }

    #[Test]
    public function authoritative_docs_state_4c_1c_2b_authorization_prerequisite_is_satisfied(): void
    {
        $gaps = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString(
            'GAP-026B authorization prerequisite is now satisfied',
            $gaps,
        );
        $this->assertStringContainsString(
            '4C-1c-2b Mapping UI authorization prerequisite is satisfied',
            $gaps,
        );
        $this->assertStringNotContainsString(
            '4C-1c-2b Mapping UI remains blocked until production cutover completes successfully',
            $gaps,
        );
    }

    #[Test]
    public function deploy_and_gaps_record_connector_discovery_production_operational_on_babypark_pilot(): void
    {
        $deploy = File::get(base_path('DEPLOY.md'));
        $gaps = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $staleDeployPhrases = [
            'Future discovery jobs',
            'installed in Task 4B-2b-1',
            'Deferred connector-worker installation',
            'no discovery job exists yet to process',
            'deferred until Task 4B-2b-1 introduces a discovery job',
            '### Connector-worker production activation gap',
            'is **not installed** on the pilot',
            'verified absent 2026-08-14; operational gap',
        ];

        foreach ($staleDeployPhrases as $phrase) {
            $this->assertStringNotContainsString(
                $phrase,
                $deploy,
                "Stale pre-discovery-worker phrase [{$phrase}] found in DEPLOY.md",
            );
        }

        $this->assertStringNotContainsString(
            'deferred until Task 4B-2b-1 introduces a discovery job',
            $gaps,
        );
        $this->assertStringContainsString(
            'ConnectorDiscoveryRunJob',
            $gaps,
        );
        $this->assertStringContainsString(
            'production-operational on the Babypark pilot',
            $gaps,
        );
        $this->assertStringContainsString(
            'Connector-worker production activation sub-gap (closed 2026-08-15)',
            $gaps,
        );
        $this->assertStringContainsString(
            'Connector-worker production activation (completed 2026-08-15)',
            $deploy,
        );
        $this->assertStringContainsString(
            'babypark-connector-queue` | **RUNNING** (verified 2026-08-15)',
            $deploy,
        );
        $this->assertStringContainsString(
            'Discovery jobs use the **connector** lane',
            $deploy,
        );
        $this->assertStringContainsString(
            '4C-1c-2b',
            $deploy,
        );
        $this->assertStringContainsString(
            '/usr/bin/php /var/www/babypark-b2b/artisan queue:work database_connectors --queue=connectors --sleep=3 --tries=3 --timeout=900 --max-time=3600',
            $deploy,
        );
        $this->assertStringContainsString(
            'RUN=a281b181-f478-4b0b-8c7d-295c26265020',
            $deploy,
        );
        $this->assertStringContainsString(
            'GAP-006 overall remains Open',
            $gaps,
        );
    }

    #[Test]
    public function deploy_records_babypark_pilot_gap_026b_cutover_completion(): void
    {
        $deploy = File::get(base_path('DEPLOY.md'));

        $this->assertStringContainsString(
            'completed successfully on 2026-08-14',
            $deploy,
        );
        $this->assertStringContainsString(
            'fb2c5a7a3f8a521a2bfca7583e57d1ae83e95bc9',
            $deploy,
        );
        $this->assertStringContainsString('### GAP-026B one-time Workspace RBAC cutover', $deploy);
    }

    #[Test]
    public function authoritative_connector_docs_do_not_claim_fixed_user_role_as_current_shipped_authorization(): void
    {
        $files = [
            'docs/03-DOMAIN_MODEL.md',
            'docs/06-UI_DESIGN_SYSTEM.md',
            'docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md',
            'docs/CONNECTOR_INTEGRATSII_PAGE_UX_CONTRACT.md',
            'docs/IMPLEMENTATION_GAPS.md',
        ];

        $forbiddenPhrases = [
            'Transitional current code (GAP-026',
            'still contains transitional fixed `User.role`',
            'Current fixed `User.role` authorization is transitional',
            'There is currently no merchant-safe read path',
            'Shipped but transitional authorization:** current `ConnectorAccountPolicy`',
            'pre-4C-1c-2a current implementation',
            'broader "read ability," includes Merchandiser',
        ];

        foreach ($files as $path) {
            $content = File::get(base_path($path));

            foreach ($forbiddenPhrases as $phrase) {
                $this->assertStringNotContainsString(
                    $phrase,
                    $content,
                    "Stale pre-B-2 current-code phrase [{$phrase}] found in {$path}",
                );
            }
        }
    }

    #[Test]
    public function authoritative_connector_docs_state_post_b2_repository_workspace_rbac_truth(): void
    {
        $domainModel = File::get(base_path('docs/03-DOMAIN_MODEL.md'));
        $uiDesign = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));
        $uxContract = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));
        $integratsii = File::get(base_path('docs/CONNECTOR_INTEGRATSII_PAGE_UX_CONTRACT.md'));

        $this->assertStringContainsString('Historical pre-B-2 shipped authorization', $domainModel);
        $this->assertStringContainsString('026B repository status (post-B-2)', $domainModel);
        $this->assertStringContainsString('management-only', $domainModel);
        $this->assertStringContainsString('manage_connector_accounts', $domainModel);

        $this->assertStringContainsString('026B repository status (post-B-2)', $uiDesign);
        $this->assertStringContainsString('ConnectorAccountCapabilityPresentation', $uiDesign);

        $this->assertStringContainsString('026B repository status (post-B-2)', $uxContract);
        $this->assertStringContainsString('ConnectorAuthorization', $uxContract);

        $this->assertStringContainsString('EligibleConnectorPlatformCatalog', $integratsii);
        $this->assertStringContainsString('frozen workspace permission matrix', $integratsii);
        $this->assertStringContainsString('manage_connector_accounts', $integratsii);
    }

    #[Test]
    public function integratsii_acceptance_criteria_require_management_only_connection_check_overlay(): void
    {
        $content = File::get(base_path('docs/CONNECTOR_INTEGRATSII_PAGE_UX_CONTRACT.md'));

        $this->assertStringContainsString(
            'Active connection-check/runtime overlay is loaded and serialized only for',
            $content,
        );
        $this->assertStringContainsString('no connection-check queries on mount', $content);
        $this->assertStringContainsString(
            'without `manage_connector_accounts` receive approved explanatory connect',
            $content,
        );
    }
}
