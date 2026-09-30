<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MasterProductWorkspaceDocumentationContractTest extends TestCase
{
    #[Test]
    public function resolved_master_product_contract_pins_universal_identity_publication_and_media_boundaries(): void
    {
        $content = File::get(base_path('docs/reviews/MASTER_PRODUCT_WORKSPACE_IMPLEMENTATION_CONTRACT_2026_09_30.md'));

        $this->assertStringContainsString('STATUS: [Resolved — 2026-09-30] — PRODUCT OWNER APPROVED', $content);
        $this->assertStringContainsString('Product Domain Contract v0.4', $content);
        $this->assertStringContainsString('Catalog Change & Publication Contract v0.3', $content);
        $this->assertStringContainsString('Media Policy v1', $content);
        $this->assertStringContainsString('Every Product has at least one immutable VariantID', $content);
        $this->assertStringContainsString('Inventory is keyed by VariantID + LocationID, never SKU', $content);
        $this->assertStringContainsString('Publication ChangeSet is an immutable subset', $content);
        $this->assertStringContainsString('Responsive widths and format variants used by a storefront/CDN are delivery/cache concerns', $content);
        $this->assertStringContainsString('There is no Magento-only Product truth', $content);
        $this->assertStringContainsString('Safe Sync stays an optional enhanced-safety profile', $content);
    }

    #[Test]
    public function documentation_map_points_to_the_resolved_master_product_contract(): void
    {
        $map = File::get(base_path('docs/Project_Documentation_Map.md'));

        $this->assertStringContainsString('MASTER_PRODUCT_WORKSPACE_IMPLEMENTATION_CONTRACT_2026_09_30.md', $map);
        $this->assertStringContainsString('Product Domain v0.4', $map);
        $this->assertStringContainsString('Catalog Change & Publication v0.3', $map);
        $this->assertStringContainsString('Media Policy v1', $map);
    }
}
