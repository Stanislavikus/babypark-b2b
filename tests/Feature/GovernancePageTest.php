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
    public function governance_document_type_uses_shared_filter_trigger(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSeeHtml('data-testid="data-list-filter-trigger"')
            ->assertSeeHtml('data-toolbar-region="filters"');
    }

    #[Test]
    public function governance_does_not_render_dec_gap_tabs_or_document_type_action(): void
    {
        $blade = File::get(resource_path('views/filament/pages/governance.blade.php'));

        $this->assertStringNotContainsString('x-filament::tabs', $blade);
        $this->assertStringNotContainsString('governance-desktop-tabs', $blade);
        $this->assertStringNotContainsString('<x-slot name="actions">', $blade);

        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertDontSeeHtml('fi-tabs')
            ->assertDontSeeHtml('data-testid="governance-desktop-tabs"');
    }

    #[Test]
    public function governance_required_document_type_filter_defaults_to_dec(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSet('documentType', 'DEC')
            ->assertSeeHtml('data-testid="governance-document-type-indicator"');

        $this->assertTrue(
            collect(Livewire::actingAs($this->platformAdmin)->test(Governance::class)->instance()->filteredDecisions())
                ->every(fn (array $decision): bool => $decision['type'] === 'DEC')
        );
    }

    #[Test]
    public function governance_document_type_filter_switches_to_gap(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->call('setDocumentType', 'GAP')
            ->assertSet('documentType', 'GAP');

        $this->assertTrue(
            collect($component->instance()->filteredDecisions())
                ->every(fn (array $decision): bool => $decision['type'] === 'GAP')
        );
    }

    #[Test]
    public function governance_document_type_change_preserves_search(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->set('search', 'pricing')
            ->call('setDocumentType', 'GAP')
            ->assertSet('documentType', 'GAP')
            ->assertSet('search', 'pricing');
    }

    #[Test]
    public function governance_document_type_change_collapses_expanded_card(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->call('toggleCard', 'DEC-001')
            ->assertSet('expandedCardId', 'DEC-001')
            ->call('setDocumentType', 'GAP')
            ->assertSet('documentType', 'GAP')
            ->assertSet('expandedCardId', null)
            ->assertSet('expandedDecision', null);
    }

    #[Test]
    public function governance_document_type_indicator_is_visible_below_toolbar(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSeeHtml('data-toolbar-region="active-filters"')
            ->assertSeeHtml('data-testid="governance-document-type-indicator"');
    }

    #[Test]
    public function governance_required_indicator_is_not_removable(): void
    {
        $html = Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->html();

        $this->assertStringNotContainsString('removeFilter', $html);
        $this->assertStringNotContainsString('aria-label="Видалити фільтр', $html);
        $this->assertStringNotContainsString('heroicon-m-x-mark', $html);
    }

    #[Test]
    public function governance_does_not_render_clear_all_for_required_document_type(): void
    {
        $blade = File::get(resource_path('views/filament/pages/governance.blade.php'));

        $this->assertStringNotContainsString('clearAllFilters', $blade);
        $this->assertStringNotContainsString('Очистити все', $blade);

        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertDontSee('Очистити все');
    }

    #[Test]
    public function governance_document_type_markup_is_rendered_once(): void
    {
        $blade = File::get(resource_path('views/filament/pages/governance.blade.php'));

        $this->assertEquals(1, substr_count($blade, 'data-testid="governance-document-type-filter"'));
        $this->assertEquals(1, substr_count($blade, 'data-testid="governance-document-type-dec"'));
        $this->assertEquals(1, substr_count($blade, 'data-testid="governance-document-type-gap"'));
    }

    #[Test]
    public function governance_mobile_overflow_reuses_same_document_type_panel(): void
    {
        $blade = File::get(resource_path('views/filament/pages/governance.blade.php'));

        $this->assertStringContainsString('governance-toolbar-panel', $blade);
        $this->assertEquals(1, substr_count($blade, 'data-testid="governance-document-type-filter"'));

        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSeeHtml('data-testid="data-list-mobile-overflow-trigger"')
            ->assertSeeHtml('data-testid="governance-document-type-filter"');
    }

    #[Test]
    public function governance_searches_number_and_title_in_active_document_type(): void
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
    public function governance_displays_counts_in_document_type_options(): void
    {
        $reader = app(CanonicalGovernanceReader::class);
        $decCount = collect($reader->listDecisions())->where('type', 'DEC')->count();
        $gapCount = collect($reader->listDecisions())->where('type', 'GAP')->count();

        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSee((string) $decCount)
            ->assertSee((string) $gapCount);
    }

    #[Test]
    public function governance_cards_are_collapsed_by_default(): void
    {
        app()->setLocale('uk');

        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSet('expandedCardId', null)
            ->assertSet('expandedDecision', null)
            ->assertDontSee(__('governance.evidence_sources'));
    }

    #[Test]
    public function governance_expanded_card_preserves_full_existing_content(): void
    {
        app()->setLocale('uk');

        $reader = app(CanonicalGovernanceReader::class);
        $expected = $reader->getDecision('DEC-001');
        $this->assertNotNull($expected);

        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->call('toggleCard', 'DEC-001')
            ->assertSet('expandedCardId', 'DEC-001')
            ->assertSet('expandedDecision.id', 'DEC-001')
            ->assertSet('expandedDecision.body', $expected['body'])
            ->assertSee(__('governance.evidence_sources'));
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

    #[Test]
    public function governance_navigation_label_uses_translation_key(): void
    {
        app()->setLocale('uk');

        $this->assertSame(__('governance.navigation_label'), Governance::getNavigationLabel());
    }

    #[Test]
    public function governance_title_uses_translation_key(): void
    {
        app()->setLocale('uk');

        $component = Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class);

        $this->assertSame(__('governance.title'), $component->instance()->getTitle());
    }

    #[Test]
    public function governance_navigation_group_uses_translation_key(): void
    {
        app()->setLocale('uk');

        $this->assertSame(__('governance.navigation_group'), Governance::getNavigationGroup());
    }

    #[Test]
    public function governance_renders_uk_labels_when_locale_is_uk(): void
    {
        app()->setLocale('uk');

        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSee(__('governance.document_type'))
            ->assertSee(__('governance.dec'))
            ->assertSee(__('governance.gap'))
            ->assertSee(__('governance.current_document_type', ['type' => 'DEC']));
    }

    #[Test]
    public function governance_renders_ru_labels_when_locale_is_ru(): void
    {
        app()->setLocale('ru');

        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSee(__('governance.document_type'))
            ->assertSee(__('governance.dec'))
            ->assertSee(__('governance.gap'))
            ->assertSee(__('governance.current_document_type', ['type' => 'DEC']));
    }

    #[Test]
    public function governance_renders_en_labels_when_locale_is_en(): void
    {
        app()->setLocale('en');

        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSee(__('governance.document_type'))
            ->assertSee(__('governance.dec'))
            ->assertSee(__('governance.gap'))
            ->assertSee(__('governance.current_document_type', ['type' => 'DEC']));
    }

    #[Test]
    public function governance_translation_keys_are_complete_for_all_supported_locales(): void
    {
        $keys = array_keys(require lang_path('uk/governance.php'));

        foreach (['uk', 'ru', 'en'] as $locale) {
            $localeKeys = array_keys(require lang_path("{$locale}/governance.php"));
            $this->assertSame($keys, $localeKeys, "Missing governance translation keys for {$locale}");
        }
    }

    #[Test]
    public function governance_has_no_hardcoded_english_title_or_ukrainian_navigation_group(): void
    {
        $contents = File::get(app_path('Filament/Pages/Governance.php'));

        $this->assertStringNotContainsString("navigationLabel = 'Governance'", $contents);
        $this->assertStringNotContainsString("title = 'Governance'", $contents);
        $this->assertStringNotContainsString("navigationGroup = 'Модель даних і коннектори'", $contents);
    }

    #[Test]
    public function gap_019_remains_open_after_governance_localization(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('GAP-019 — Application-wide UI Localization', $content);
        $this->assertStringContainsString('Governance navigation/title/group and page-specific controls now use', $content);
        $this->assertStringContainsString('**Status:** Open', $content);
    }
}
