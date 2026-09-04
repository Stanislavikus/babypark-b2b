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
    public function store_setup_overview_does_not_claim_catalog_access_without_successful_connection_check(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('uk');

        $account = ConnectorAccount::factory()->create([
            'last_checked_at' => Carbon::parse('2026-09-01T10:11:12+00:00'),
            'last_successful_check_at' => null,
        ]);

        Livewire::test(StoreSetupPacketHarness::class, ['record' => $account])
            ->assertSee(__('connectors.ui.layer_a.catalog.label'))
            ->assertSee(__('connectors.ui.layer_a.status.needs_attention'))
            ->assertDontSee(__('connectors.ui.layer_a.last_successful_check').':');
    }

    #[Test]
    public function store_setup_overview_shows_last_successful_connection_check_timestamp_when_present(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('uk');

        $account = ConnectorAccount::factory()->create([
            'last_checked_at' => Carbon::parse('2026-09-01T10:11:12+00:00'),
            'last_successful_check_at' => Carbon::parse('2026-09-01T12:34:56+00:00'),
        ]);

        Livewire::test(StoreSetupPacketHarness::class, ['record' => $account])
            ->assertSee(__('connectors.ui.layer_a.catalog.label'))
            ->assertSee(__('connectors.ui.layer_a.status.needs_attention'))
            ->assertSee(__('connectors.ui.layer_a.last_successful_check').':');
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
