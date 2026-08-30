<?php

namespace Tests\Feature\Connectors;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeSafeSyncReadinessPresentationTest extends TestCase
{
    #[Test]
    public function ukrainian_readiness_copy_exposes_three_business_states_without_technical_vocabulary(): void
    {
        $copy = implode(' ', [
            __('connectors.ui.readiness.ready.title', locale: 'uk'),
            __('connectors.ui.readiness.setup_required.title', locale: 'uk'),
            __('connectors.ui.readiness.setup_required.body', locale: 'uk'),
            __('connectors.ui.readiness.update_required.title', locale: 'uk'),
            __('connectors.ui.readiness.update_required.body', locale: 'uk'),
            __('connectors.ui.readiness.check_again', locale: 'uk'),
        ]);

        $this->assertStringContainsString('Готово до синхронізації', $copy);
        $this->assertStringContainsString('Перевірити ще раз', $copy);

        foreach (['PHP', '2.4.', 'Composer', 'B2BPlatform_MagentoSafeSync', 'HTTP', 'stage3e-r1', 'operation', 'entity_bound', 'AdobeInvalid'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $copy);
        }
    }

    #[Test]
    public function connector_account_action_uses_transient_resolver_without_readiness_persistence(): void
    {
        $page = file_get_contents(app_path('Filament/Resources/ConnectorAccountResource/Pages/ViewConnectorAccount.php'));
        $resolver = file_get_contents(app_path('Services/Connectors/AdobeSafeSyncComponentReadinessResolver.php'));

        $this->assertStringContainsString('AdobeSafeSyncComponentReadinessResolver', $page);
        $this->assertStringContainsString('AdobeSafeSyncRequiredOperation::SimpleProductWrite', $page);
        $this->assertStringNotContainsString('ConnectorAccount::update', $resolver);
        $this->assertStringNotContainsString('save()', $resolver);
        $this->assertSame([], glob(database_path('migrations/*readiness*')) ?: []);
    }
}
