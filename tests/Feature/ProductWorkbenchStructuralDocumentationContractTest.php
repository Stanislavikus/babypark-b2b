<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductWorkbenchStructuralDocumentationContractTest extends TestCase
{
    #[Test]
    public function structural_contract_is_product_owner_resolved(): void
    {
        $content = File::get(base_path('docs/reviews/PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md'));

        $this->assertStringContainsString('STATUS: [Resolved — 2026-09-21] — PRODUCT OWNER APPROVED', $content);
        $this->assertStringContainsString('**`Огляд`**', $content);
        $this->assertStringContainsString('**`Публікація`**', $content);
        $this->assertStringContainsString('Remote Catalogue Projection V2', $content);
    }

    #[Test]
    public function target_classification_freeze_preserves_defaults_sparse_overrides_and_existing_remote_truth(): void
    {
        $content = File::get(base_path('docs/reviews/PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md'));

        $this->assertStringContainsString('ProductType -> Magento Attribute Set', $content);
        $this->assertStringContainsString('no Product override rows -> inherit Category default', $content);
        $this->assertStringContainsString('current observed remote `attribute_set_id` is the structural context', $content);
        $this->assertStringContainsString('Per-Product effective Attribute Set therefore requires a named planner/metadata migration', $content);
        $this->assertStringContainsString('Do not add a stored ConnectorAccount classification counter', $content);
    }

    #[Test]
    public function authoritative_domain_and_ux_contracts_reflect_the_2026_09_21_freeze(): void
    {
        $domain = File::get(base_path('docs/03-DOMAIN_MODEL.md'));
        $ux = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));
        $channel = File::get(base_path('docs/PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md'));

        $this->assertStringContainsString('[Resolved — 2026-09-21] Multiple-Attribute-Set target model', $domain);
        $this->assertStringContainsString('Magento target category + Attribute Set classification [Resolved — 2026-09-21]', $domain);
        $this->assertStringContainsString('Product Workbench + channel work surface (Resolved — 2026-09-21)', $ux);
        $this->assertStringContainsString('Product Workbench presentation-order amendment', $channel);
        $this->assertStringContainsString('Remote Catalogue Projection V2', $channel);
    }

    #[Test]
    public function documentation_map_points_to_the_resolved_structural_contract(): void
    {
        $map = File::get(base_path('docs/Project_Documentation_Map.md'));

        $this->assertStringContainsString('PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md', $map);
        $this->assertStringContainsString('[Resolved — 2026-09-21]', $map);
        $this->assertStringNotContainsString('PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_FREEZE_CANDIDATE_2026_09_21.md', $map);
    }
}
