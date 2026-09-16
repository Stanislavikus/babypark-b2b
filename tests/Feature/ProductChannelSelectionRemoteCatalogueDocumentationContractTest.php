<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductChannelSelectionRemoteCatalogueDocumentationContractTest extends TestCase
{
    #[Test]
    public function product_channel_selection_and_remote_catalogue_contract_is_frozen(): void
    {
        $content = File::get(base_path('docs/PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md'));

        $this->assertStringContainsString('**Status:** FROZEN — STOP-AND-AMEND 2026-09-15', $content);
        $this->assertStringContainsString('selection.mode = explicit_products', $content);
        $this->assertStringContainsString('`SyncConfiguration`', $content);
        $this->assertStringContainsString('Product-level membership', $content);
        $this->assertStringContainsString('one Master Product catalogue', $content);
        $this->assertStringContainsString('must reference one concrete Completed Preview', $content);
        $this->assertStringContainsString('source_preview_run_id', $content);
    }

    #[Test]
    public function remote_catalogue_is_not_product_truth_or_identity_trust(): void
    {
        $content = File::get(base_path('docs/PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md'));

        $this->assertStringContainsString('Remote Catalogue Projection is not a second Product truth', $content);
        $this->assertStringContainsString('Remote catalogue state belongs to the remote account/target context', $content);
        $this->assertStringContainsString('`ExternalRecordLink` remains the sole persisted platform authority', $content);
        $this->assertStringContainsString('failed, cancelled, incomplete, or pagination-invalid scan MUST NOT partially replace', $content);
        $this->assertStringContainsString('Do NOT inherit the current schema-discovery `MAX_PAGES` / `MAX_FIELDS = 10,000`', $content);
        $this->assertStringContainsString('Remote-only records may be shown in a separate secondary surface', $content);
    }

    #[Test]
    public function seo_ai_media_boundaries_remain_provider_neutral_and_governed(): void
    {
        $content = File::get(base_path('docs/PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md'));

        $this->assertStringContainsString('Provider-neutral SEO/Search Evidence boundary', $content);
        $this->assertStringContainsString('DataForSEO plus one or more SERP/ranking/keyword/competitor providers', $content);
        $this->assertStringContainsString('Remote-only Magento records may later be analysis targets without having `product_id`', $content);
        $this->assertStringContainsString('AI is a cross-domain proposal mechanism, not a connector-specific writer', $content);
        $this->assertStringContainsString('Future AI image enhancement', $content);
    }

    #[Test]
    public function domain_and_ux_summaries_reference_the_frozen_contract(): void
    {
        $domain = File::get(base_path('docs/03-DOMAIN_MODEL.md'));
        $ux = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));
        $ui = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('### Product → Channel Selection + Remote Catalogue Projection', $domain);
        $this->assertStringContainsString('[Resolved — 2026-09-15]', $domain);
        $this->assertStringContainsString('is no longer the target merchant-selection model', $domain);
        $this->assertStringContainsString('### Product selection + channel work surface (Resolved — 2026-09-15)', $ux);
        $this->assertStringContainsString('Remote-only records are shown separately from local', $ux);
        $this->assertStringContainsString('being discovered', $ux);
        $this->assertStringContainsString('**Channel Product selection/workspace (Resolved 2026-09-15):**', $ui);
    }

    #[Test]
    public function documentation_map_and_gap_ledger_record_the_new_freeze(): void
    {
        $map = File::get(base_path('docs/Project_Documentation_Map.md'));
        $gaps = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('## PRODUCT_CHANNEL_SELECTION_REMOTE_CATALOGUE_CONTRACT.md', $map);
        $this->assertStringContainsString('Product/channel membership, remote catalogue projection, Preview/Live selection set', $map);
        $this->assertStringContainsString('**Product→Channel Selection + Remote Catalogue Projection**', $gaps);
        $this->assertStringContainsString('**Runtime implemented through merchant linking candidate integration; production-readiness evidence still pending**', $gaps);
        $this->assertStringContainsString('Magento >10k enumeration still requires representative real-target proof before production-readiness claims', $gaps);
        $this->assertStringContainsString('Adobe Products/Export/Live support remains **false**', $gaps);
    }
}
