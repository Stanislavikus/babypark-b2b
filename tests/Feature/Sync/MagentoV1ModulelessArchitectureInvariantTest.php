<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MagentoV1ModulelessArchitectureInvariantTest extends TestCase
{
    #[Test]
    public function canonical_contract_freezes_standard_v1_as_zero_install_stock_rest(): void
    {
        $content = File::get(base_path(
            'docs/connectors/adobe-commerce/MAGENTO_V1_MODULELESS_CONNECTOR_CONTRACT.md',
        ));

        $this->assertStringContainsString('[Resolved Product Decision — 2026-09-24]', $content);
        $this->assertStringContainsString('universal **zero-install** connector', $content);
        $this->assertStringContainsString('standard Adobe Commerce / Magento Admin REST API', $content);
        $this->assertStringContainsString('The platform adapts to Magento. Magento is not modified to adapt to the platform.', $content);
        $this->assertStringContainsString('standard Product CREATE', $content);
        $this->assertStringContainsString('standard linked Product UPDATE', $content);
        $this->assertStringContainsString('optional Enhanced-Safety profile', $content);
        $this->assertStringContainsString('until the standard moduleless Magento V1 connector is complete', preg_replace('/\s+/u', ' ', $content));
        $this->assertStringContainsString('merchant explicitly chooses the Enhanced-Safety profile', $content);
        $this->assertStringContainsString('SUPERSEDED for the standard Magento V1 product path', $content);
    }

    #[Test]
    public function documentation_map_and_ai_agreement_make_the_contract_mandatory(): void
    {
        $map = File::get(base_path('docs/Project_Documentation_Map.md'));
        $agreement = File::get(base_path('docs/05-AI_WORKING_AGREEMENT.md'));

        $this->assertStringContainsString('MAGENTO_V1_MODULELESS_CONNECTOR_CONTRACT.md', $map);
        $this->assertStringContainsString('mandatory pre-read for every Magento/Adobe Commerce task', $map);

        $this->assertStringContainsString('### Magento V1 Special Guardrail — Resolved Product Decision 2026-09-24', $agreement);
        $this->assertStringContainsString('moduleless, zero-install connector over the stock', $agreement);
        $this->assertStringContainsString('MUST STOP', $agreement);
        $this->assertStringContainsString('deferred until the standard moduleless', $agreement);
        $this->assertStringContainsString('opt-in Enhanced-Safety profile', $agreement);
    }

    #[Test]
    public function historical_stage_three_e_is_explicitly_superseded_for_standard_v1(): void
    {
        $domain = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString(
            '[SUPERSEDED FOR STANDARD MAGENTO V1 — Resolved Product Decision 2026-09-24]',
            $domain,
        );
        $this->assertStringContainsString(
            'Standard Magento V1 is a universal **moduleless / zero-install connector',
            $domain,
        );
        $this->assertStringContainsString(
            'Where this historical Stage 3E text conflicts with that contract for the standard',
            $domain,
        );
        $this->assertStringContainsString(
            'distributed only as an opt-in Enhanced-Safety profile',
            $domain,
        );
    }

    #[Test]
    public function delivery_protocol_defers_safe_sync_until_standard_v1_is_complete(): void
    {
        $delivery = File::get(base_path('docs/09-CONNECTOR_DELIVERY_PROTOCOL.md'));

        $this->assertStringContainsString(
            'defers further Safe Sync productization until the',
            $delivery,
        );
        $this->assertStringContainsString(
            'standard moduleless Magento V1 connector is complete',
            $delivery,
        );
        $this->assertStringContainsString(
            'Only a merchant who explicitly chooses the Enhanced-Safety profile',
            $delivery,
        );
        $this->assertStringContainsString(
            'standard Product CREATE, standard linked Product UPDATE',
            preg_replace('/\s+/u', ' ', $delivery),
        );
    }

    #[Test]
    public function production_intended_standard_v1_runtime_has_no_safe_sync_dependency(): void
    {
        foreach ([
            'app/Support/Connectors/AdobePaaS/AdobeProductExportLiveCapability.php',
            'app/Support/Connectors/AdobePaaS/Command/AdobeProductSimpleCommandExecutor.php',
            'app/Support/Connectors/AdobePaaS/Command/AdobeProductStockSimpleWriteExecutor.php',
            'app/Support/Connectors/AdobePaaS/Command/AdobeProductModulelessSimpleCreateExecutor.php',
        ] as $path) {
            $content = File::get(base_path($path));

            $this->assertStringNotContainsString(
                'SafeSync',
                $content,
                "Standard Magento V1 runtime file {$path} must not depend on Safe Sync.",
            );
        }
    }

    #[Test]
    public function moduleless_create_uses_existing_stock_product_transport(): void
    {
        $content = File::get(base_path(
            'app/Support/Connectors/AdobePaaS/Command/AdobeProductModulelessSimpleCreateExecutor.php',
        ));

        $this->assertStringContainsString('getProductWithContext', $content);
        $this->assertStringContainsString('postProduct', $content);
        $this->assertStringContainsString('adobe_create_post_reconciled', $content);
        $this->assertStringNotContainsString('safe-sync', strtolower($content));
    }
}
