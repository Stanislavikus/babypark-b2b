<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\PriceInspector;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\Resolution\PriceResolutionStatus;
use App\Services\Pricing\Resolution\PriceResolutionTracePresenter;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PriceInspectorTest extends TestCase
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
            'name' => 'Inspector Admin',
            'email' => 'inspector-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_access_price_inspector_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/price-inspector')
            ->assertOk();
    }

    public function test_unauthenticated_user_cannot_access_price_inspector(): void
    {
        $this->get('/admin/price-inspector')
            ->assertRedirect();
    }

    public function test_inactive_user_cannot_access_price_inspector(): void
    {
        $inactive = User::query()->create([
            'name' => 'Inactive',
            'email' => 'inactive@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => false,
        ]);

        $this->actingAs($inactive)
            ->get('/admin/price-inspector')
            ->assertForbidden();
    }

    public function test_inspector_uses_resolve_with_trace(): void
    {
        $customer = $this->createCustomer($this->workspace);
        $variant = $this->createVariant($this->workspace);
        $list = $this->createPriceList($this->workspace);
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00);

        Livewire::actingAs($this->admin)
            ->test(PriceInspector::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'variant_id' => $variant->id,
                'quantity' => 1,
                'effective_at' => now()->toDateTimeString(),
            ])
            ->call('resolvePrice')
            ->assertSet('resultStatus', PriceResolutionStatus::Resolved->value)
            ->assertSet('presentation.headline', __('price_inspector.headline.resolved'))
            ->assertSet('presentation.tone', 'success');
    }

    public function test_inspector_tenant_isolation_for_customer_selector(): void
    {
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Foreign Workspace',
            'is_default' => false,
        ]);

        $foreignCustomer = $this->createCustomer($otherWorkspace);
        $localCustomer = $this->createCustomer($this->workspace);
        $workspaceId = $this->workspace->id;

        $localIds = Customer::query()
            ->where('workspace_id', $workspaceId)
            ->pluck('id')
            ->all();

        $this->assertContains($localCustomer->id, $localIds);
        $this->assertNotContains($foreignCustomer->id, $localIds);
    }

    public function test_inspector_tenant_isolation_for_variant_selector(): void
    {
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Foreign Workspace 2',
            'is_default' => false,
        ]);

        $localVariant = $this->createVariant($this->workspace);
        $foreignVariant = $this->createVariant($otherWorkspace);
        $workspaceId = $this->workspace->id;

        $localIds = ProductVariant::query()
            ->where('workspace_id', $workspaceId)
            ->pluck('id')
            ->all();

        $this->assertContains($localVariant->id, $localIds);
        $this->assertNotContains($foreignVariant->id, $localIds);
    }

    public function test_inspector_product_filter_limits_variants(): void
    {
        $customer = $this->createCustomer($this->workspace);
        $variant = $this->createVariant($this->workspace);
        $product = $variant->product;

        $otherProduct = Product::create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'OTHER-SKU',
            'name' => 'Other Product',
            'is_active' => true,
        ]);

        ProductVariant::create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $otherProduct->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'OTHER-VAR',
            'is_active' => true,
            'available_quantity_cache' => 5,
            'availability_status' => 'in_stock',
        ]);

        $list = $this->createPriceList($this->workspace);
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00);

        Livewire::actingAs($this->admin)
            ->test(PriceInspector::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->call('resolvePrice')
            ->assertSet('resultStatus', PriceResolutionStatus::Resolved->value);
    }

    public function test_inspector_output_for_all_three_statuses(): void
    {
        $presenter = app(PriceResolutionTracePresenter::class);
        $resolver = app(PriceResolver::class);
        $customer = $this->createCustomer($this->workspace);

        $resolvedVariant = $this->createVariant($this->workspace);
        $list = $this->createPriceList($this->workspace);
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $resolvedVariant, 100.00);

        $resolved = $resolver->resolveWithTrace($resolvedVariant, $customer, 1);
        $resolvedOutput = $presenter->present($resolved);
        $this->assertStringContainsString('resolved', $resolvedOutput);
        $this->assertStringContainsString('matched', $resolvedOutput);

        $unavailableVariant = $this->createVariant($this->workspace);
        $unavailable = $resolver->resolveWithTrace($unavailableVariant, $customer, 1);
        $unavailableOutput = $presenter->present($unavailable);
        $this->assertStringContainsString('unavailable', $unavailableOutput);
        $this->assertStringContainsString('all_sources_exhausted', $unavailableOutput);
        $this->assertStringContainsString('failed', $unavailableOutput);

        if (DB::connection()->getDriverName() !== 'mysql') {
            $configWorkspace = Workspace::query()->create([
                'name' => 'Config Workspace',
                'is_default' => false,
            ]);
            $configCustomer = $this->createCustomer($configWorkspace);
            $configVariant = $this->createVariant($configWorkspace);

            PriceList::withoutWorkspaceScope()
                ->where('workspace_id', $configWorkspace->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $configError = $resolver->resolveWithTrace($configVariant, $configCustomer, 1);
            $configOutput = $presenter->present($configError);
            $this->assertStringContainsString('configuration_error', $configOutput);
            $this->assertStringContainsString('default_price_list_misconfigured', $configOutput);
        }

        $futureList = $this->createPriceList($this->workspace);
        $futureVariant = $this->createVariant($this->workspace);
        $futureCustomer = $this->createCustomer($this->workspace);
        $futureCustomer->update(['default_price_list_id' => $futureList->id]);
        $this->createPriceListItem(
            $futureList,
            $futureVariant,
            100.00,
            validFrom: CarbonImmutable::parse('2026-12-01'),
        );

        $futureResult = $resolver->resolveWithTrace(
            $futureVariant,
            $futureCustomer,
            1,
            CarbonImmutable::parse('2026-07-01'),
        );
        $futureOutput = $presenter->present($futureResult);
        $this->assertStringContainsString('not_yet_effective', $futureOutput);
    }

    public function test_resolved_page_shows_human_friendly_content(): void
    {
        $customer = $this->createCustomer($this->workspace);
        $variant = $this->createVariant($this->workspace);
        $list = $this->createPriceList($this->workspace);
        $list->update(['name' => 'Оптовий Україна']);
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00);

        $response = Livewire::actingAs($this->admin)
            ->test(PriceInspector::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->call('resolvePrice');

        $html = $response->html();

        $this->assertStringContainsString(__('price_inspector.headline.resolved'), $html);
        $this->assertStringContainsString(__('price_inspector.section.decision_path'), $html);
        $this->assertStringContainsString('Оптовий Україна', $html);
        $this->assertStringNotContainsString('Текстовий вивід', $html);
        $this->assertStringNotContainsString('all_sources_exhausted', $html);
    }

    public function test_unavailable_page_shows_recommended_actions(): void
    {
        $customer = $this->createCustomer($this->workspace);
        $variant = $this->createVariant($this->workspace);

        $response = Livewire::actingAs($this->admin)
            ->test(PriceInspector::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->call('resolvePrice');

        $html = $response->html();

        $this->assertStringContainsString(__('price_inspector.headline.unavailable'), $html);
        $this->assertStringContainsString(__('price_inspector.section.what_to_fix'), $html);
        $this->assertStringContainsString(__('price_inspector.action.set_base_price'), $html);
    }

    public function test_technical_details_are_inside_collapsed_details_element(): void
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

        $this->assertStringContainsString('<details', $html);
        $this->assertStringNotContainsString('<details open', $html);
        $this->assertStringContainsString(__('price_inspector.section.technical_details'), $html);

        $detailsPos = strpos($html, '<details');
        $detailsClosePos = strpos($html, '</details>', $detailsPos);
        $mainContent = substr($html, 0, $detailsPos);

        $this->assertStringNotContainsString('price_list_id', $mainContent);
        $this->assertStringNotContainsString('all_sources_exhausted', $mainContent);
        $this->assertStringNotContainsString('item_missing', $mainContent);

        $technicalContent = substr($html, $detailsPos, $detailsClosePos - $detailsPos);
        $this->assertStringContainsString('price_list_id', $technicalContent);
    }

    public function test_page_title_and_subheading_are_human_friendly(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/price-inspector');

        $response->assertOk();
        $response->assertSee(__('price_inspector.page.title'));
        $response->assertSee(__('price_inspector.page.subheading'));
    }
}
