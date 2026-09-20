<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectorAdapter;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MagentoV1ProductsExportLiveTruthFlipDocumentationContractTest extends TestCase
{
    #[Test]
    public function current_support_truth_is_export_live_true_import_live_false(): void
    {
        $adapter = new AdobePaaSConnectorAdapter;

        $this->assertTrue($adapter->supports(
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
            SyncRunMode::Preview,
        ));
        $this->assertTrue($adapter->supports(
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
            SyncRunMode::Live,
        ));
        $this->assertFalse($adapter->supports(
            SyncDataDomain::Products,
            SyncSemanticOperation::Import,
            SyncRunMode::Live,
        ));

        $domain = File::get(base_path('docs/03-DOMAIN_MODEL.md'));
        $this->assertStringContainsString(
            '[Resolved — 2026-09-19 — standard moduleless Magento V1 truth flip]',
            $domain,
        );
        $this->assertStringContainsString('Products / Export / Live = **true**', $domain);
        $this->assertStringContainsString('Products / Import / Live = **false**', $domain);
        $this->assertStringContainsString('Magento Product CREATE = **unsupported in V1**', $domain);

        $atlas = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));
        $this->assertStringContainsString(
            'Adobe Products/Export/Live support truth | SUPPORTED (public) — [Resolved 2026-09-19]',
            $atlas,
        );
        $this->assertStringContainsString('Live runtime readiness | IMPLEMENTED + REAL-TARGET VERIFIED', $atlas);
    }

    #[Test]
    public function highest_precedence_ux_contract_matches_current_moduleless_live_truth(): void
    {
        $ux = File::get(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));
        $this->assertSame(1, preg_match(
            '/^## 17\\. Merchant First-Live UX.*?$(.*?)^## 18\\. Per-item Live linking/ms',
            $ux,
            $firstLiveMatch,
        ));
        $this->assertSame(1, preg_match(
            '/^## 18\\. Per-item Live linking.*?$(.*?)^## 19\\. Connector Account Overview/ms',
            $ux,
            $linkingMatch,
        ));
        $firstLive = $firstLiveMatch[1];
        $linking = $linkingMatch[1];

        $this->assertStringNotContainsString('advertised Live support remains **false**', $firstLive);
        $this->assertStringContainsString('Products / Export / Live = **true**', $ux);
        $this->assertStringContainsString('Products / Import / Live = **false**', $ux);
        $this->assertStringContainsString('Magento Product CREATE = **unsupported in V1**', $ux);
        $this->assertStringContainsString('Safe Sync is optional Enhanced Safety', $ux);

        $this->assertStringContainsString('**Truth-flip status: COMPLETED 2026-09-19.**', $ux);
        $this->assertStringContainsString('certified **standard moduleless** path', $ux);
        $this->assertStringContainsString('post-write identity mismatch is ambiguous', $ux);
        $this->assertStringNotContainsString(
            'Truthful Adobe Products/Export/Live advertised support remains **false**',
            $linking,
        );

        $domain = File::get(base_path('docs/03-DOMAIN_MODEL.md'));
        $this->assertStringContainsString(
            '[Resolved — 2026-09-20 — architecture-closure amendment]',
            $domain,
        );
        $this->assertStringContainsString('**detects but does not structurally prevent** this narrow race', $domain);
        $this->assertStringContainsString('`stock_post_write_identity_mismatch`', $domain);
    }

    #[Test]
    public function product_create_primitive_has_no_production_command_caller(): void
    {
        foreach (File::allFiles(app_path('Support/Connectors/AdobePaaS/Command')) as $file) {
            if ($file->getFilename() === 'AdobeProductRemoteStateClient.php') {
                continue;
            }

            $this->assertStringNotContainsString(
                'postProduct(',
                File::get($file->getPathname()),
                $file->getPathname(),
            );
        }
    }

    #[Test]
    public function durable_evidence_proves_noop_write_restore_and_cleanup(): void
    {
        $evidence = json_decode(
            File::get(base_path(
                'docs/connectors/adobe-commerce/magento_v1_products_export_live_certification_2026_09_19.json'
            )),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('PASS', $evidence['result']);
        $this->assertTrue($evidence['support_truth']['products_export_live']);
        $this->assertFalse($evidence['support_truth']['products_import_live']);
        $this->assertFalse($evidence['support_truth']['product_create']);

        $currency = $evidence['bounded_smoke']['currency_mismatch_fail_closed']['items'][0];
        $this->assertSame('not_applied', $currency['outcome']);
        $this->assertSame(0, $currency['findings'][0]['context']['consequential_write_attempts']);

        $noop = $evidence['bounded_smoke']['noop_live']['items'][0];
        $this->assertSame('synchronized', $noop['outcome']);
        $this->assertSame('stock_state_already_matches', $noop['findings'][0]['context']['reason_code']);
        $this->assertSame(0, $noop['findings'][0]['context']['consequential_write_attempts']);

        foreach (['live_change', 'live_restore'] as $phase) {
            $item = $evidence['bounded_smoke'][$phase]['items'][0];
            $this->assertSame('synchronized', $item['outcome']);
            $this->assertSame('stock_write_verified', $item['findings'][0]['context']['reason_code']);
            $this->assertSame(1, $item['findings'][0]['context']['consequential_write_attempts']);
            $this->assertSame(1, $item['findings'][0]['context']['reconciliation_get_attempts']);
        }
        $this->assertTrue($evidence['postconditions']['restore_verified']);
        $this->assertTrue($evidence['postconditions']['identity_preserved']);
        $this->assertTrue($evidence['postconditions']['price_preserved']);
        $this->assertTrue($evidence['postconditions']['category_preserved']);
        $this->assertTrue($evidence['postconditions']['url_key_preserved']);

        $cleanup = $evidence['local_cleanup'];
        $this->assertSame('CLEANUP_OK', $cleanup['result']);
        $this->assertSame($cleanup['expected_revision'], $cleanup['configuration_revision']);
        $this->assertSame(0, $cleanup['temporary_erl_remaining']);
        $this->assertSame(0, $cleanup['temporary_category_mapping_remaining']);
    }

    #[Test]
    public function pending_ledger_keeps_followups_explicitly_non_blocking_for_export_live(): void
    {
        $ledger = File::get(base_path(
            'docs/connectors/adobe-commerce/MAGENTO_V1_PENDING_CERTIFICATION_ITEMS.md'
        ));

        $this->assertStringContainsString(
            'Adobe Products / Export / Live is now supported for the certified standard moduleless V1 scope',
            $ledger,
        );
        $this->assertStringContainsString('P-04', $ledger);
        $this->assertStringContainsString('Export Live gate: **NON-BLOCKING for the certified target/scope**', $ledger);
        $this->assertStringContainsString('Export Live gate: **NOT APPLICABLE** — P-07 governs Receive/Import', $ledger);
        $this->assertStringContainsString('Adobe Products / Import / Live remains false', $ledger);
    }
}
