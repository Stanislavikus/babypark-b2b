<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Models\ConnectorAccount;
use App\Support\Connectors\ConnectorAccountUiState;
use App\Support\Connectors\ConnectorUiFormatter;
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
    public function direct_store_setup_render_is_safe_and_never_renders_an_empty_next_step(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('uk');

        $account = ConnectorAccount::factory()->create([
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'last_checked_at' => Carbon::parse('2026-09-01T10:11:12+00:00'),
            'last_successful_check_at' => null,
        ]);

        Livewire::test(StoreSetupPacketHarness::class, ['record' => $account])
            ->assertSee(__('connectors.ui.layer_a.next_step.heading'))
            ->assertSee(__('connectors.ui.layer_a.next_step.setup_admin_required'))
            ->assertDontSee(__('connectors.ui.layer_a.catalog.label'))
            ->assertDontSee(__('connectors.ui.layer_a.fields.label'))
            ->assertDontSee(__('connectors.ui.layer_a.images.label'))
            ->assertDontSee(__('connectors.ui.layer_a.last_successful_check'));
    }

    #[Test]
    public function connection_timestamp_belongs_to_runtime_state_not_store_setup(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('uk');

        $account = ConnectorAccount::factory()->create([
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'last_checked_at' => Carbon::parse('2026-09-01T10:11:12+00:00'),
            'last_successful_check_at' => Carbon::parse('2026-09-01T12:34:56+00:00'),
        ]);

        Livewire::test(StoreSetupPacketHarness::class, ['record' => $account])
            ->assertDontSee(__('connectors.ui.layer_a.checked_at', [
                'time' => ConnectorUiFormatter::formatDateTime($account->last_successful_check_at),
            ]))
            ->assertDontSee(__('connectors.ui.layer_a.last_successful_check'));

        Livewire::test(RuntimeStatePacketHarness::class, ['record' => $account])
            ->assertSee(__('connectors.enums.account_connection_status.connected'))
            ->assertDontSee(__('connectors.ui.layer_a.status.connected'))
            ->assertSee(__('connectors.ui.layer_a.checked_at', [
                'time' => ConnectorUiFormatter::formatDateTime($account->last_successful_check_at),
            ]));
    }
}

class RuntimeStatePacketHarness extends Component
{
    public ConnectorAccount $record;

    public function mount(ConnectorAccount $record): void
    {
        $this->record = $record;
    }

    public function render()
    {
        return view('filament.connector-accounts.runtime-state', [
            'uiState' => app(ConnectorAccountUiState::class),
            'showActiveConnectionCheck' => false,
        ]);
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
