<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Governance;
use App\Models\User;
use App\Support\CanonicalRegistry\CanonicalGovernanceReader;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GovernancePageTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->create([
            'name' => 'Platform Admin',
            'email' => 'governance-admin-'.uniqid().'@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function governance_separates_dec_and_gap_tabs(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSet('activeTab', 'DEC')
            ->assertSee('DEC (')
            ->assertSee('GAP (')
            ->call('setActiveTab', 'GAP')
            ->assertSet('activeTab', 'GAP');

        $this->assertTrue(
            collect($component->instance()->filteredDecisions())
                ->every(fn (array $decision): bool => $decision['type'] === 'GAP')
        );
    }

    #[Test]
    public function governance_displays_counts_in_tab_labels(): void
    {
        $reader = app(CanonicalGovernanceReader::class);
        $decCount = collect($reader->listDecisions())->where('type', 'DEC')->count();
        $gapCount = collect($reader->listDecisions())->where('type', 'GAP')->count();

        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSee('DEC ('.$decCount.')')
            ->assertSee('GAP ('.$gapCount.')');
    }

    #[Test]
    public function governance_searches_number_and_title_in_active_tab(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->set('search', '006')
            ->assertSet('search', '006');

        $filtered = $component->instance()->filteredDecisions();
        $this->assertNotEmpty($filtered);
        $this->assertTrue(
            collect($filtered)->every(
                fn (array $decision): bool => str_contains($decision['id'], '006')
                    || str_contains($decision['title'], '006')
            )
        );

        $component
            ->set('search', 'mpn variant binding')
            ->assertSet('search', 'mpn variant binding');

        $this->assertTrue(
            collect($component->instance()->filteredDecisions())->contains(
                fn (array $decision): bool => $decision['id'] === 'DEC-001'
            )
        );
    }

    #[Test]
    public function governance_cards_are_collapsed_by_default(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSet('expandedCardId', null)
            ->assertSet('expandedDecision', null)
            ->assertDontSee('Джерела доказів');
    }

    #[Test]
    public function governance_expanded_card_preserves_full_existing_content(): void
    {
        $reader = app(CanonicalGovernanceReader::class);
        $expected = $reader->getDecision('DEC-001');
        $this->assertNotNull($expected);

        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->call('toggleCard', 'DEC-001')
            ->assertSet('expandedCardId', 'DEC-001')
            ->assertSet('expandedDecision.id', 'DEC-001')
            ->assertSet('expandedDecision.body', $expected['body'])
            ->assertSee('Джерела доказів');
    }

    #[Test]
    public function governance_tab_switch_preserves_search_and_collapses_cards(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->call('toggleCard', 'DEC-001')
            ->assertSet('expandedCardId', 'DEC-001')
            ->set('search', 'pricing')
            ->call('setActiveTab', 'GAP')
            ->assertSet('activeTab', 'GAP')
            ->assertSet('search', 'pricing')
            ->assertSet('expandedCardId', null)
            ->assertSet('expandedDecision', null);
    }

    #[Test]
    public function governance_remains_read_only(): void
    {
        $blade = File::get(resource_path('views/filament/pages/governance.blade.php'));

        $this->assertStringNotContainsString('Approve', $blade);
        $this->assertStringNotContainsString('Reject', $blade);
        $this->assertStringNotContainsString('EditAction', $blade);
        $this->assertStringNotContainsString('wire:submit', $blade);
    }

    #[Test]
    public function governance_page_renders_without_error_for_platform_admin(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSuccessful()
            ->assertSet('decisions', fn (array $decisions): bool => $decisions !== []);
    }
}
