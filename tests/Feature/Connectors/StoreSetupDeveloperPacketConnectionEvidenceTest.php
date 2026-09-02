<?php

namespace Tests\Feature\Connectors;

use App\Models\ConnectorAccount;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StoreSetupDeveloperPacketConnectionEvidenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function developer_packet_does_not_treat_failed_check_timestamp_as_verified_connection_evidence(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('en');

        $account = ConnectorAccount::factory()->create([
            'last_checked_at' => Carbon::parse('2026-09-01T10:11:12+00:00'),
            'last_successful_check_at' => null,
        ]);

        $expectedEvidenceLine = __('connectors.ui.readiness.developer.packet.connection_evidence_missing');

        Livewire::test(StoreSetupPacketHarness::class, ['record' => $account])
            ->assertSee($expectedEvidenceLine)
            ->assertDontSee($account->last_checked_at->toIso8601String());
    }

    #[Test]
    public function developer_packet_uses_last_successful_connection_check_timestamp_when_present(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('en');

        $account = ConnectorAccount::factory()->create([
            'last_checked_at' => Carbon::parse('2026-09-01T10:11:12+00:00'),
            'last_successful_check_at' => Carbon::parse('2026-09-01T12:34:56+00:00'),
        ]);

        $expectedEvidenceLine = __('connectors.ui.readiness.developer.packet.connection_evidence_at', [
            'value' => $account->last_successful_check_at->toIso8601String(),
        ]);

        $forbiddenEvidenceLine = __('connectors.ui.readiness.developer.packet.connection_evidence_at', [
            'value' => $account->last_checked_at->toIso8601String(),
        ]);

        Livewire::test(StoreSetupPacketHarness::class, ['record' => $account])
            ->assertSee($expectedEvidenceLine)
            ->assertDontSee($forbiddenEvidenceLine);
    }
}

class StoreSetupPacketHarness extends Component
{
    public ConnectorAccount $record;

    public string $storeSetupState = 'READY';

    public ?string $storeSetupBaselineMessage = null;

    public ?string $storeSetupModuleVersion = null;

    public ?string $storeSetupApplicationVersion = null;

    public ?string $storeSetupPhpVersion = null;

    public ?string $storeSetupStockMagentoVersionEvidence = null;

    /** @var array<string, mixed> */
    public array $storeSetupDiagnostics = [];

    public function mount(ConnectorAccount $record): void
    {
        $this->record = $record;
    }

    /**
     * @return array{enabled: bool, label: string, disabled_reason: ?string}
     */
    public function storeSetupActionState(): array
    {
        return [
            'enabled' => false,
            'label' => '',
            'disabled_reason' => null,
        ];
    }

    public function render()
    {
        return view('filament.connector-accounts.store-setup');
    }
}
