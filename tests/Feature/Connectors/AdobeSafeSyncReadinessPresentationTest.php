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
            __('connectors.ui.readiness.not_checked.title', locale: $locale),
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
        $this->assertSame('Automatic data synchronization', __('connectors.ui.readiness.store_setup', locale: 'en'));
        $this->assertSame('Check readiness', __('connectors.ui.readiness.check', locale: 'en'));
        $this->assertSame('Check again', __('connectors.ui.readiness.check_again', locale: 'en'));
        $this->assertSame('Checking readiness…', __('connectors.ui.readiness.checking', locale: 'en'));
        $this->assertSame(
            'Ready to send product changes to Magento',
            __('connectors.ui.readiness.ready.title', locale: 'en'),
        );
        $this->assertSame('Sending product changes to Magento needs technical preparation', __('connectors.ui.readiness.setup_required.title', locale: 'en'));
        $this->assertSame('The synchronization component must be updated', __('connectors.ui.readiness.update_required.title', locale: 'en'));

        $this->assertStringContainsString('does not change Magento data', __('connectors.ui.readiness.not_checked.body', locale: 'en'));
        $this->assertSame(
            'We confirmed that this store can receive simple product changes from the platform.',
            __('connectors.ui.readiness.ready.body', locale: 'en'),
        );
        $this->assertStringContainsString('technical requirements', __('connectors.ui.readiness.setup_required.body', locale: 'en'));
        $this->assertStringContainsString('currently unavailable', __('connectors.ui.readiness.setup_required.body', locale: 'en'));
        $this->assertStringContainsString('technical requirements', __('connectors.ui.readiness.update_required.body', locale: 'en'));
        $this->assertStringContainsString('Magento connection', __('connectors.ui.readiness.baseline_failure.guidance', locale: 'en'));
        $this->assertStringNotContainsString('safe product synchronization', __('connectors.ui.readiness.not_checked.body', locale: 'en'));
        $this->assertStringNotContainsString('safe product synchronization', __('connectors.ui.readiness.ready.body', locale: 'en'));
        $this->assertStringNotContainsString('safe product synchronization', __('connectors.ui.readiness.setup_required.body', locale: 'en'));
        $this->assertStringNotContainsString('safe product synchronization', __('connectors.ui.readiness.update_required.body', locale: 'en'));
    }

    #[Test]
    public function connector_account_action_uses_transient_resolver_without_readiness_persistence(): void
    {
        $page = file_get_contents(app_path('Filament/Resources/ConnectorAccountResource/Pages/ViewConnectorAccount.php'));
        $resource = file_get_contents(app_path('Filament/Resources/ConnectorAccountResource.php'));
        $resolver = file_get_contents(app_path('Services/Connectors/AdobeSafeSyncComponentReadinessResolver.php'));
        $view = file_get_contents(resource_path('views/filament/connector-accounts/store-setup.blade.php'));

        $this->assertStringContainsString('AdobeSafeSyncComponentReadinessResolver', $page);
        $this->assertStringContainsString('AdobeSafeSyncRequiredOperation::SimpleProductWrite', $page);
        $this->assertStringContainsString('public function checkStoreSetupAction(): Action', $page);
        $this->assertStringContainsString('private function executeStoreSetupCheck(): void', $page);
        $this->assertStringContainsString("Action::make('checkStoreSetup')", $page);
        $this->assertStringContainsString("->authorize('runConnectionCheck')", $page);
        $this->assertStringContainsString("->disabled(fn (): bool => ! \$this->storeSetupActionState()['enabled'])", $page);
        $this->assertStringContainsString("\$this->storeSetupState = 'NOT_CHECKED';", $page);
        $this->assertStringNotContainsString('public function checkStoreSetup(): void', $page);
        $this->assertStringContainsString('shouldShowStoreSetupEntry', $resource);
        $this->assertStringContainsString('->visible(fn (ConnectorAccount $record): bool => static::shouldShowStoreSetupEntry($record))', $resource);
        $this->assertStringNotContainsString("Gate::forUser(\$user)->allows('runConnectionCheck', \$record);", $resource);
        $this->assertStringContainsString('{{ $this->checkStoreSetupAction }}', $view);
        $this->assertStringContainsString("__('connectors.ui.readiness.not_checked.body')", $view);
        $this->assertStringContainsString("'connectors.ui.readiness.not_checked.title'", $view);
        $this->assertStringContainsString("'connectors.ui.readiness.ready.title'", $view);
        $this->assertStringContainsString("'connectors.ui.readiness.ready.body'", $view);
        $this->assertStringContainsString('$baselineMessage = $this->storeSetupBaselineMessage;', $view);
        $this->assertStringContainsString('$stockMagentoVersionEvidence = $this->storeSetupStockMagentoVersionEvidence;', $view);
        $this->assertStringNotContainsString('$livewire->storeSetupBaselineMessage', $view);
        $this->assertStringNotContainsString('wire:click="checkStoreSetup"', $view);
        $this->assertStringNotContainsString('ConnectorAccount::update', $resolver);
        $this->assertStringNotContainsString('save()', $resolver);
        $this->assertSame([], glob(database_path('migrations/*readiness*')) ?: []);
    }
}
