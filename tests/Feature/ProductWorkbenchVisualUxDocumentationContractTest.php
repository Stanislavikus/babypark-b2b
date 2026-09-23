<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductWorkbenchVisualUxDocumentationContractTest extends TestCase
{
    #[Test]
    public function visual_contract_is_resolved_with_two_top_level_views(): void
    {
        $content = File::get(base_path('docs/reviews/PRODUCT_WORKBENCH_VISUAL_UX_CONTRACT_2026_09_22.md'));

        $this->assertStringContainsString('STATUS: [Resolved — 2026-09-22] — PRODUCT OWNER APPROVED', $content);
        $this->assertStringContainsString('[ Огляд ]   [ Публікація ]', $content);
        $this->assertStringContainsString('No permanent top-level `Зв\'язки` tab in first scope.', $content);
        $this->assertStringContainsString('Focus mode', $content);
        $this->assertStringContainsString('2026-09-23 visual Stop-and-Amend', $content);
        $this->assertStringContainsString("Filament's native desktop-collapsible sidebar", $content);
        $this->assertStringContainsString('standard Filament table filter surface', $content);
        $this->assertStringContainsString('one compact horizontal header line', $content);
        $this->assertStringContainsString('status board', $content);
        $this->assertStringContainsString('browser-like tab strip', $content);
        $this->assertStringContainsString('Матриця полів', $content);
        $this->assertStringContainsString('same right-side slide-over interaction', $content);
        $this->assertStringContainsString('official Magento PNG mark', $content);
        $logoPath = public_path('images/connectors/magento-mark.png');
        $this->assertFileExists($logoPath);
        $this->assertSame(IMAGETYPE_PNG, getimagesize($logoPath)[2]);

        $header = File::get(resource_path('views/components/filament/product-workbench-header.blade.php'));
        $this->assertStringContainsString('width="28"', $header);
        $this->assertStringContainsString('height="28"', $header);

        $actionSortHeader = File::get(resource_path('views/filament/pages/sync/partials/product-workbench-action-sort-header.blade.php'));
        $this->assertStringContainsString('fi-ta-header-cell-sort-btn', $actionSortHeader);
        $this->assertStringContainsString('heroicon-m-chevron-down', $actionSortHeader);
        $this->assertStringNotContainsString('heroicon-m-chevron-up-down', $actionSortHeader);
        $this->assertSame(1, substr_count($actionSortHeader, '<x-filament::icon'));
        $this->assertStringContainsString('Row / photo interaction — 2026-09-23 clarification', $content);
        $this->assertStringContainsString('shared image lightbox', $content);
        $this->assertStringContainsString('Відкрити повну картку', $content);

        $designSystem = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));
        $this->assertStringContainsString('Canonical project reference', $designSystem);
        $this->assertStringContainsString('Матриця полів', $designSystem);
        $this->assertStringContainsString('native `Filament\\Tables\\Table` controls', $designSystem);
    }

    #[Test]
    public function data_state_is_distinct_from_readiness_and_problems(): void
    {
        $content = File::get(base_path('docs/reviews/PRODUCT_WORKBENCH_VISUAL_UX_CONTRACT_2026_09_22.md'));

        $this->assertStringContainsString('## 4. Стан даних', $content);
        $this->assertStringContainsString('`Готовність` — can goal X be executed safely now?', $content);
        $this->assertStringContainsString('`Проблеми` — concrete findings/causes', $content);
        $this->assertStringContainsString('The percentage is derived; do not persist a redundant stale `82` field', $content);
    }

    #[Test]
    public function ai_and_seo_use_governed_fields_without_becoming_product_truth(): void
    {
        $content = File::get(base_path('docs/reviews/PRODUCT_WORKBENCH_VISUAL_UX_CONTRACT_2026_09_22.md'));

        $this->assertStringContainsString('AI must use the same governed Product field architecture as manual editing.', $content);
        $this->assertStringContainsString('AI never silently becomes Product/connector truth.', $content);
        $this->assertStringContainsString('SEO is a separate profile/domain', $content);
        $this->assertStringContainsString('Magento auto-generation is an acceptable target fallback', $content);
    }

    #[Test]
    public function master_product_creation_remains_single_product_truth(): void
    {
        $content = File::get(base_path('docs/reviews/PRODUCT_WORKBENCH_VISUAL_UX_CONTRACT_2026_09_22.md'));

        $this->assertStringContainsString('There must not be a separate “Magento-only Product” creation truth.', $content);
        $this->assertStringContainsString('A new Product is always a Master Product in the SaaS.', $content);
        $this->assertStringContainsString('`Magento → Публікація → Створити товар`', $content);
    }

    #[Test]
    public function projection_v2_study_records_real_target_no_n_plus_one_evidence(): void
    {
        $content = File::get(base_path('docs/reviews/PRODUCT_WORKBENCH_REMOTE_CATALOGUE_PROJECTION_V2_REAL_MAGENTO_STUDY_2026_09_22.md'));

        $this->assertStringContainsString('Operation performed: READ only. No Magento writes.', $content);
        $this->assertStringContainsString('response body: 144,484 bytes', $content);
        $this->assertStringContainsString('without per-Product HTTP N+1 calls', $content);
        $this->assertStringContainsString('page size around **100**', $content);
        $this->assertStringContainsString('do not hardcode `manufacturer` as platform brand', $content);
    }

    #[Test]
    public function documentation_map_points_to_resolved_visual_contract_and_projection_task(): void
    {
        $map = File::get(base_path('docs/Project_Documentation_Map.md'));

        $this->assertStringContainsString('PRODUCT_WORKBENCH_VISUAL_UX_CONTRACT_2026_09_22.md', $map);
        $this->assertStringContainsString('PRODUCT_WORKBENCH_PROJECTION_V2_IMPLEMENTATION_TASK_DRAFT_2026_09_22.md', $map);
        $this->assertStringNotContainsString('PRODUCT_WORKBENCH_VISUAL_UX_CONTRACT_FREEZE_CANDIDATE_2026_09_21.md', $map);
    }
}
