<?php

namespace Tests\Feature\Connectors;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeSafeSyncReadinessPresentationTest extends TestCase
{
    #[Test]
    public function layer_a_overview_copy_avoids_technical_vocabulary(): void
    {
        $view = file_get_contents(resource_path('views/filament/connector-accounts/store-setup.blade.php'));

        $this->assertNotFalse($view);
        $this->assertStringContainsString('connectors.ui.layer_a.check_does_not_mutate', $view);
        $this->assertStringNotContainsString('connectors.ui.layer_a.what_we_checked_heading', $view);
        $this->assertStringNotContainsString('Перевірка не змінює дані в Magento.', $view);
        $this->assertStringNotContainsString('ЩО МИ ПЕРЕВІРИЛИ', $view);

        foreach ([
            'B2BPlatform_MagentoSafeSync',
            'AdobeSafeSync',
            'safe-sync',
            'OAuth',
            'ACL',
            'Composer',
            'PHP',
            '/V1/safe-sync/',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $view);
        }

        $this->assertStringNotContainsString('connectors.ui.layer_a.status.not_checked', $view);
    }

    #[Test]
    public function readiness_contract_keeps_the_concrete_business_operation_vocabulary(): void
    {
        $contract = file_get_contents(base_path('docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md'));

        $this->assertNotFalse($contract);
        $this->assertStringContainsString('Передача змін товарів у Magento', $contract);
        $this->assertStringNotContainsString('Magento Product-aligned automatic synchronization readiness', $contract);
        $this->assertStringNotContainsString('Автоматична синхронізація даних', $contract);
    }

    #[Test]
    public function connector_account_page_has_only_expected_actions(): void
    {
        $page = file_get_contents(app_path('Filament/Resources/ConnectorAccountResource/Pages/ViewConnectorAccount.php'));

        $this->assertNotFalse($page);
        $this->assertStringContainsString("Action::make('runConnectionCheck')", $page);
        $this->assertStringNotContainsString("Action::make('openAdobeExportSetup')", $page);
        $this->assertStringContainsString("->color('gray')", $page);
        $this->assertStringNotContainsString('checkStoreSetup', $page);
        $this->assertStringNotContainsString('AdobeSafeSyncComponentReadinessResolver', $page);
        $this->assertStringNotContainsString('AdobeSafeSyncRequiredOperation::SimpleProductWrite', $page);
    }
}
