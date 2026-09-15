<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\PriceInspector;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PriceInspectorNavigationDomTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    private User $admin;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->defaultWorkspace();
        $this->admin = User::query()->create([
            'name' => 'Nav DOM Admin',
            'email' => 'nav-dom@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_resolved_matched_step_action_has_full_navigation_contract(): void
    {
        $customer = $this->createCustomer($this->workspace);
        $variant = $this->createVariant($this->workspace);
        $list = $this->createPriceList($this->workspace);
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00);

        $html = Livewire::actingAs($this->admin)
            ->test(PriceInspector::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->call('resolvePrice')
            ->html();

        $this->assertNavigationLinkContract($html, __('price_inspector.action.open_price_list'));
    }

    public function test_unavailable_recommended_actions_have_full_navigation_contract(): void
    {
        $customer = $this->createCustomer($this->workspace);
        $variant = $this->createVariant($this->workspace);

        $html = Livewire::actingAs($this->admin)
            ->test(PriceInspector::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->call('resolvePrice')
            ->html();

        $this->assertNavigationLinkContract($html, __('price_inspector.action.set_base_price'));
        $this->assertNavigationLinkContract($html, __('price_inspector.action.assign_price_list'));
    }

    public function test_base_price_cache_matched_action_has_full_navigation_contract(): void
    {
        $customer = $this->createCustomer($this->workspace);
        $variant = $this->createVariant($this->workspace, basePriceCache: 42.00);

        $html = Livewire::actingAs($this->admin)
            ->test(PriceInspector::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->call('resolvePrice')
            ->html();

        $this->assertNavigationLinkContract($html, __('price_inspector.action.open_product'));
    }

    private function assertNavigationLinkContract(string $html, string $label): void
    {
        $labelPosition = strpos($html, $label);
        $this->assertNotFalse($labelPosition, "Label [{$label}] not found in HTML");

        $anchorStart = strrpos(substr($html, 0, $labelPosition), '<a ');
        $this->assertNotFalse($anchorStart, "No anchor before label [{$label}]");

        $anchorEnd = strpos($html, '</a>', $labelPosition);
        $this->assertNotFalse($anchorEnd, "No closing anchor for label [{$label}]");

        $anchor = substr($html, $anchorStart, $anchorEnd - $anchorStart + 4);

        $this->assertStringContainsString('href="', $anchor, "Missing href for [{$label}]");
        $this->assertStringContainsString('target="_blank"', $anchor, "Missing target=_blank for [{$label}]");
        $this->assertStringContainsString('noopener', $anchor, "Missing rel=noopener for [{$label}]");
        $this->assertStringContainsString('sr-only', $anchor, "Missing sr-only for [{$label}]");
        $this->assertStringContainsString(__('price_inspector.opens_in_new_tab'), $anchor, "Missing localized sr-only text for [{$label}]");
    }
}
