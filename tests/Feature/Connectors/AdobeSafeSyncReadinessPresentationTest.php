<?php

namespace Tests\Feature\Connectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeSafeSyncReadinessPresentationTest extends TestCase
{
    public static function readinessLocaleProvider(): array
    {
        return [
            'english' => ['en'],
            'russian' => ['ru'],
            'ukrainian' => ['uk'],
        ];
    }

    #[Test]
    #[DataProvider('readinessLocaleProvider')]
    public function readiness_copy_exposes_three_business_states_without_technical_vocabulary(string $locale): void
    {
        $copy = implode(' ', [
            __('connectors.ui.readiness.store_setup', locale: $locale),
            __('connectors.ui.readiness.check', locale: $locale),
            __('connectors.ui.readiness.check_again', locale: $locale),
            __('connectors.ui.readiness.checking', locale: $locale),
            __('connectors.ui.readiness.not_checked.body', locale: $locale),
            __('connectors.ui.readiness.ready.title', locale: $locale),
            __('connectors.ui.readiness.ready.body', locale: $locale),
            __('connectors.ui.readiness.setup_required.title', locale: $locale),
            __('connectors.ui.readiness.setup_required.body', locale: $locale),
            __('connectors.ui.readiness.update_required.title', locale: $locale),
            __('connectors.ui.readiness.update_required.body', locale: $locale),
            __('connectors.ui.readiness.baseline_failure.title', locale: $locale),
            __('connectors.ui.readiness.baseline_failure.guidance', locale: $locale),
        ]);

        $this->assertNotSame(trim($copy), '');
        $this->assertStringNotContainsString('Connector', $copy);
        $this->assertStringNotContainsString('component', $copy);
        $this->assertStringNotContainsString('компонент', $copy);
        $this->assertStringNotContainsString('module', strtolower($copy));
        $this->assertStringNotContainsString('модул', mb_strtolower($copy));

        foreach ([
            'PHP', '2.4.', 'Composer', 'B2BPlatform_MagentoSafeSync', 'HTTP',
            'stage3e-r1', 'operation', 'entity_bound', 'AdobeInvalid', 'safe-sync',
            'handshake', 'омпозер', 'андак',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $copy);
        }
    }

    #[Test]
    public function english_readiness_copy_uses_merchant_safe_phrasing(): void
    {
        $this->assertSame('Store setup', __('connectors.ui.readiness.store_setup', locale: 'en'));
        $this->assertSame('Check store setup', __('connectors.ui.readiness.check', locale: 'en'));
        $this->assertSame('Check again', __('connectors.ui.readiness.check_again', locale: 'en'));
        $this->assertSame('Checking store setup…', __('connectors.ui.readiness.checking', locale: 'en'));
        $this->assertSame('Store setup is ready', __('connectors.ui.readiness.ready.title', locale: 'en'));
        $this->assertSame('Store setup needs to be completed', __('connectors.ui.readiness.setup_required.title', locale: 'en'));
        $this->assertSame('Store setup needs to be updated', __('connectors.ui.readiness.update_required.title', locale: 'en'));

        $this->assertStringContainsString('safe product synchronization', __('connectors.ui.readiness.not_checked.body', locale: 'en'));
        $this->assertStringContainsString('Magento connection works', __('connectors.ui.readiness.setup_required.body', locale: 'en'));
        $this->assertStringContainsString('store administrator', __('connectors.ui.readiness.setup_required.body', locale: 'en'));
        $this->assertStringContainsString('Magento connection works', __('connectors.ui.readiness.update_required.body', locale: 'en'));
        $this->assertStringContainsString('Magento connection', __('connectors.ui.readiness.baseline_failure.guidance', locale: 'en'));
    }

    #[Test]
    public function connector_account_action_uses_transient_resolver_without_readiness_persistence(): void
    {
        $page = file_get_contents(app_path('Filament/Resources/ConnectorAccountResource/Pages/ViewConnectorAccount.php'));
        $resolver = file_get_contents(app_path('Services/Connectors/AdobeSafeSyncComponentReadinessResolver.php'));

        $this->assertStringContainsString('AdobeSafeSyncComponentReadinessResolver', $page);
        $this->assertStringContainsString('AdobeSafeSyncRequiredOperation::SimpleProductWrite', $page);
        $this->assertStringContainsString('checkStoreSetup', $page);
        $this->assertStringNotContainsString('checkComponentReadiness', $page);
        $this->assertStringNotContainsString('ConnectorAccount::update', $resolver);
        $this->assertStringNotContainsString('save()', $resolver);
        $this->assertSame([], glob(database_path('migrations/*readiness*')) ?: []);
    }
}
