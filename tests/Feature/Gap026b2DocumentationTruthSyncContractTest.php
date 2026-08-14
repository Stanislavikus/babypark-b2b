<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards authoritative Connector docs against stale pre-B-2 "current code"
 * authorization statements coexisting with GAP-026B-2 Implemented repository status.
 */
class Gap026b2DocumentationTruthSyncContractTest extends TestCase
{
    #[Test]
    public function implementation_gaps_records_gap_026b_2_as_implemented_repository_runtime(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString(
            '| **GAP-026B-2 — Authority & Presentation Cutover** | **Implemented (repository ready for production cutover; production EXECUTE not yet performed).**',
            $content,
        );
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
