<?php

namespace Tests\Feature;

use App\Livewire\Cabinet\Catalog;
use App\Models\PriceList;
use App\Support\Pricing\CustomerFacingPriceLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class CustomerFacingPresentationTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    public function test_catalog_renders_without_internal_configuration_text_when_no_default_list(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL enforces single default price list via database constraint.');
        }

        $workspace = $this->defaultWorkspace();
        PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $customer = $this->createCustomer($workspace);
        $variant = $this->createVariant($workspace, basePriceCache: 42.00);
        $variant->product->update(['name' => 'UniqueConfigErrorProductXYZ']);

        $this->actingAs($customer, 'customer');

        $html = Livewire::test(Catalog::class)
            ->set('search', 'UniqueConfigErrorProductXYZ')
            ->assertOk()
            ->html();

        $this->assertStringNotContainsString('DefaultPriceListMisconfigured', $html);
        $this->assertStringNotContainsString('workspace_id', $html);
        $this->assertStringNotContainsString('active default price list', $html);
        $this->assertStringContainsString('Помилка конфігурації цін', $html);
    }

    public function test_customer_facing_price_label_sanitizes_internal_patterns(): void
    {
        $raw = 'Workspace abc has multiple active default price lists. workspace_id=abc';
        $sanitized = CustomerFacingPriceLabel::sanitize($raw);

        $this->assertSame('Помилка конфігурації цін', $sanitized);
    }
}
