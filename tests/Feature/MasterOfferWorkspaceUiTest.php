<?php

namespace Tests\Feature;

use App\Enums\PriceListItemStatus;
use App\Enums\PriceListStatus;
use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Pricing\PriceResolver;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class MasterOfferWorkspaceUiTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $actor;

    private WorkspaceUser $membership;

    private PriceList $defaultPriceList;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->workspace = $this->defaultWorkspace();
        $this->actor = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $this->membership = $this->makeWorkspaceMembership($this->workspace, $this->actor);
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Offer UI manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($this->membership, $role);

        $this->defaultPriceList = PriceList::withoutWorkspaceScope()->firstOrCreate(
            [
                'workspace_id' => $this->workspace->id,
                'is_default' => true,
            ],
            [
                'name' => 'Workspace Default',
                'currency' => 'UAH',
                'priority' => 0,
                'status' => PriceListStatus::Active,
            ],
        );

        if ($this->defaultPriceList->status !== PriceListStatus::Active) {
            $this->defaultPriceList->update(['status' => PriceListStatus::Active]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function simple_product_offer_action_is_real_and_hides_internal_variant(): void
    {
        [$product, $variant] = $this->productWithVariants([
            ['sku' => null],
        ]);

        $action = TestAction::make('edit_offer')
            ->schemaComponent('offer_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible($action)
            ->mountAction($action)
            ->assertActionMounted($action)
            ->assertFormFieldHidden('variant_id')
            ->assertFormFieldExists('sell_net')
            ->assertFormFieldExists('compare_at_net')
            ->unmountAction()
            ->callAction($action, [
                'variant_id' => (string) $variant->id,
                'expected_item_id' => null,
                'expected_regular_net' => null,
                'expected_sale_net' => null,
                'effective_vat_rate' => '20.00',
                'sell_net' => '90.00',
                'compare_at_net' => '120.00',
            ])
            ->assertNotified('Ціну оновлено');

        $item = PriceListItem::withoutWorkspaceScope()
            ->where('price_list_id', $this->defaultPriceList->id)
            ->where('product_variant_id', $variant->id)
            ->where('quantity_min', 1)
            ->sole();

        $this->assertSame('120.00', (string) $item->price);
        $this->assertSame('90.00', (string) $item->sale_price);

        $resolved = app(PriceResolver::class)->resolveDefault($variant->fresh());
        $this->assertSame(90.0, $resolved->effectiveNetPrice);
        $this->assertTrue($resolved->isOnSale);
    }

    #[Test]
    public function existing_promotion_mounts_as_semantic_sell_and_compare_at_fields(): void
    {
        [$product, $variant] = $this->productWithVariants([
            ['sku' => null],
        ]);
        $item = $this->priceItem($variant, '120.00', '90.00');

        $action = TestAction::make('edit_offer')
            ->schemaComponent('offer_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction($action)
            ->assertFormFieldHidden('variant_id')
            ->assertActionDataSet([
                'expected_item_id' => $item->id,
                'expected_regular_net' => '120.00',
                'expected_sale_net' => '90.00',
                'sell_net' => '90.00',
                'compare_at_net' => '120.00',
            ]);
    }

    #[Test]
    public function clearing_compare_at_in_ui_keeps_current_sell_as_normal_price(): void
    {
        [$product, $variant] = $this->productWithVariants([
            ['sku' => 'SIMPLE-SALE'],
        ]);
        $item = $this->priceItem($variant, '120.00', '90.00');

        $action = TestAction::make('edit_offer')
            ->schemaComponent('offer_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction($action, [
                'variant_id' => (string) $variant->id,
                'expected_item_id' => $item->id,
                'expected_regular_net' => '120.00',
                'expected_sale_net' => '90.00',
                'effective_vat_rate' => '20.00',
                'sell_net' => '90.00',
                'compare_at_net' => null,
            ])
            ->assertNotified('Ціну оновлено');

        $this->assertSame('90.00', (string) $item->fresh()->price);
        $this->assertNull($item->fresh()->sale_price);
    }

    #[Test]
    public function configurable_offer_action_updates_only_selected_explicit_variant(): void
    {
        [$product, $first, $second] = $this->productWithVariants([
            ['sku' => 'CONF-RED'],
            ['sku' => 'CONF-BLUE'],
        ]);

        $firstItem = $this->priceItem($first, '100.00');
        $secondItem = $this->priceItem($second, '110.00');

        $action = TestAction::make('edit_offer')
            ->schemaComponent('offer_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction($action, [
                'variant_id' => (string) $second->id,
                'expected_item_id' => $secondItem->id,
                'expected_regular_net' => '110.00',
                'expected_sale_net' => null,
                'effective_vat_rate' => '20.00',
                'sell_net' => '95.00',
                'compare_at_net' => '110.00',
            ])
            ->assertNotified('Ціну оновлено');

        $this->assertSame('100.00', (string) $firstItem->fresh()->price);
        $this->assertNull($firstItem->fresh()->sale_price);
        $this->assertSame('110.00', (string) $secondItem->fresh()->price);
        $this->assertSame('95.00', (string) $secondItem->fresh()->sale_price);
    }

    #[Test]
    public function cost_is_hidden_and_redacted_without_dedicated_permission(): void
    {
        [$product, $variant] = $this->productWithVariants([
            ['sku' => 'NO-COST-PERMISSION'],
        ]);
        $variant->update(['cost_price' => '40.00']);
        $item = $this->priceItem($variant, '100.00');

        $action = TestAction::make('edit_offer')
            ->schemaComponent('offer_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction($action)
            ->assertFormFieldHidden('cost_net')
            ->assertActionDataSet([
                'expected_item_id' => $item->id,
                'expected_cost_net' => null,
                'cost_net' => null,
                'write_cost' => false,
            ]);

        Livewire::actingAs($this->actor)
            ->test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertSchemaComponentHidden('cost_price_summary', 'infolist')
            ->assertSchemaComponentHidden('admin_margin', 'infolist');
    }

    #[Test]
    public function dedicated_permission_exposes_cost_and_saves_it_with_the_offer(): void
    {
        [$product, $variant] = $this->productWithVariants([
            ['sku' => 'COST-PERMISSION'],
        ]);
        $variant->update(['cost_price' => '40.00']);
        $item = $this->priceItem($variant, '100.00');
        $this->grantCostPermission();

        $action = TestAction::make('edit_offer')
            ->schemaComponent('offer_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction($action)
            ->assertFormFieldVisible('cost_net')
            ->assertActionDataSet([
                'expected_item_id' => $item->id,
                'expected_cost_net' => '40.00',
                'cost_net' => '40.00',
                'write_cost' => true,
            ])
            ->unmountAction()
            ->callAction($action, [
                'variant_id' => (string) $variant->id,
                'expected_item_id' => $item->id,
                'expected_regular_net' => '100.00',
                'expected_sale_net' => null,
                'expected_cost_net' => '40.00',
                'write_cost' => true,
                'effective_vat_rate' => '20.00',
                'sell_net' => '95.00',
                'compare_at_net' => '110.00',
                'cost_net' => '55.00',
            ])
            ->assertNotified('Ціну оновлено');

        $this->assertSame('55.00', (string) $variant->fresh()->cost_price);
        $this->assertSame('110.00', (string) $item->fresh()->price);
        $this->assertSame('95.00', (string) $item->fresh()->sale_price);

        Livewire::actingAs($this->actor)
            ->test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertSchemaComponentVisible('cost_price_summary', 'infolist')
            ->assertSchemaComponentVisible('admin_margin', 'infolist');
    }

    #[Test]
    public function source_owned_product_offer_action_is_read_only(): void
    {
        [$product] = $this->productWithVariants([
            ['sku' => 'SOURCE-OWNED-OFFER'],
        ]);
        $product->update(['onec_guid' => '11111111-1111-4111-8111-111111111111']);

        $action = TestAction::make('edit_offer')
            ->schemaComponent('offer_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible($action)
            ->assertActionDisabled($action);
    }

    #[Test]
    public function source_owned_variant_offer_fields_are_read_only_even_on_manual_product(): void
    {
        [$product, $variant] = $this->productWithVariants([
            ['sku' => 'VARIANT-SOURCE-OWNED-OFFER'],
        ]);
        $variant->update(['onec_guid' => '55555555-5555-4555-8555-555555555555']);
        $this->priceItem($variant, '120.00', '90.00');
        $this->grantCostPermission();

        $action = TestAction::make('edit_offer')
            ->schemaComponent('offer_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible($action)
            ->mountAction($action)
            ->assertActionMounted($action)
            ->assertFormFieldHidden('variant_id')
            ->assertFormFieldDisabled('sell_net')
            ->assertFormFieldDisabled('compare_at_net')
            ->assertFormFieldDisabled('cost_net');
    }

    /**
     * @param  list<array{sku:?string}>  $variants
     * @return array<int, Product|ProductVariant>
     */
    private function productWithVariants(array $variants): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'name' => 'Offer UI product',
            'is_active' => true,
        ]);

        $created = [];

        foreach ($variants as $spec) {
            $created[] = ProductVariant::withoutWorkspaceScope()->create([
                'workspace_id' => $this->workspace->id,
                'product_id' => $product->id,
                'onec_guid' => null,
                'sku' => $spec['sku'],
                'attributes' => [],
                'is_active' => true,
            ]);
        }

        return [$product, ...$created];
    }

    private function grantCostPermission(): void
    {
        $role = $this->createRoleWithPermissions(
            $this->workspace->id,
            'Product cost manager',
            [WorkspacePermissions::MANAGE_PRODUCT_COST],
        );

        $this->assignRoleToMembership($this->membership, $role);
    }

    private function priceItem(
        ProductVariant $variant,
        string $regular,
        ?string $sale = null,
    ): PriceListItem {
        return PriceListItem::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'price_list_id' => $this->defaultPriceList->id,
            'product_variant_id' => $variant->id,
            'quantity_min' => 1,
            'price' => $regular,
            'sale_price' => $sale,
            'vat_rate' => null,
            'valid_from' => null,
            'valid_until' => null,
            'status' => PriceListItemStatus::Active,
        ]);
    }
}
