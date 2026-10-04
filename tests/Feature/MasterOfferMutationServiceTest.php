<?php

namespace Tests\Feature;

use App\Enums\PriceListItemStatus;
use App\Enums\PriceListStatus;
use App\Enums\UserRole;
use App\Exceptions\Pricing\MasterOfferMutationException;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Pricing\MasterOfferMutationService;
use App\Services\Pricing\MasterOfferReadService;
use App\Services\Pricing\PriceResolver;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class MasterOfferMutationServiceTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $actor;

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

        $membership = $this->makeWorkspaceMembership($this->workspace, $this->actor);
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Master offer manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);

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
    }

    #[Test]
    public function normal_sell_materializes_default_qty_one_item_without_touching_base_cache(): void
    {
        [$product, $variant] = $this->manualProductWithVariant(basePriceCache: '77.00');

        $state = app(MasterOfferReadService::class)->state($variant);
        $this->assertTrue($state['editable']);
        $this->assertSame('77.00', $state['sell_net']);
        $this->assertNull($state['expected_item_id']);

        $item = app(MasterOfferMutationService::class)->setPrice(
            $this->actor,
            $this->workspace,
            $product,
            $variant,
            expectedItemId: $state['expected_item_id'],
            expectedRegularNet: $state['expected_regular_net'],
            expectedSaleNet: $state['expected_sale_net'],
            sellNet: '100.00',
            compareAtNet: null,
        );

        $this->assertSame('100.00', (string) $item->price);
        $this->assertNull($item->sale_price);
        $this->assertSame(1, $item->quantity_min);
        $this->assertSame(PriceListItemStatus::Active, $item->status);
        $this->assertNull($item->vat_rate);
        $this->assertSame('77.00', (string) $variant->fresh()->base_price_cache);

        $resolved = app(PriceResolver::class)->resolveDefault($variant->fresh());
        $this->assertSame(100.0, $resolved->regularNetPrice);
        $this->assertNull($resolved->salePrice);
        $this->assertSame(100.0, $resolved->effectiveNetPrice);
        $this->assertFalse($resolved->isOnSale);
    }

    #[Test]
    public function promotion_maps_semantic_sell_and_compare_at_to_existing_regular_and_sale_columns(): void
    {
        [$product, $variant] = $this->manualProductWithVariant();
        $item = $this->priceItem($variant, '120.00');

        app(MasterOfferMutationService::class)->setPrice(
            $this->actor,
            $this->workspace,
            $product,
            $variant,
            expectedItemId: $item->id,
            expectedRegularNet: '120.00',
            expectedSaleNet: null,
            sellNet: '90.00',
            compareAtNet: '120.00',
        );

        $fresh = $item->fresh();
        $this->assertSame('120.00', (string) $fresh->price);
        $this->assertSame('90.00', (string) $fresh->sale_price);

        $state = app(MasterOfferReadService::class)->state($variant->fresh());
        $this->assertSame('90.00', $state['sell_net']);
        $this->assertSame('120.00', $state['compare_at_net']);

        $resolved = app(PriceResolver::class)->resolveDefault($variant->fresh());
        $this->assertSame(90.0, $resolved->effectiveNetPrice);
        $this->assertTrue($resolved->isOnSale);
    }

    #[Test]
    public function clearing_compare_at_keeps_current_sell_as_the_new_normal_price(): void
    {
        [$product, $variant] = $this->manualProductWithVariant();
        $item = $this->priceItem($variant, '120.00', '90.00');

        app(MasterOfferMutationService::class)->setPrice(
            $this->actor,
            $this->workspace,
            $product,
            $variant,
            expectedItemId: $item->id,
            expectedRegularNet: '120.00',
            expectedSaleNet: '90.00',
            sellNet: '90.00',
            compareAtNet: null,
        );

        $fresh = $item->fresh();
        $this->assertSame('90.00', (string) $fresh->price);
        $this->assertNull($fresh->sale_price);

        $state = app(MasterOfferReadService::class)->state($variant->fresh());
        $this->assertSame('90.00', $state['sell_net']);
        $this->assertNull($state['compare_at_net']);
    }

    #[Test]
    public function compare_at_must_be_strictly_greater_than_sell_before_any_write(): void
    {
        [$product, $variant] = $this->manualProductWithVariant();
        $item = $this->priceItem($variant, '100.00');

        foreach (['100.00', '99.99'] as $compareAt) {
            try {
                app(MasterOfferMutationService::class)->setPrice(
                    $this->actor,
                    $this->workspace,
                    $product,
                    $variant,
                    expectedItemId: $item->id,
                    expectedRegularNet: '100.00',
                    expectedSaleNet: null,
                    sellNet: '100.00',
                    compareAtNet: $compareAt,
                );

                $this->fail('Expected invalid compare-at price to be rejected.');
            } catch (MasterOfferMutationException $exception) {
                $this->assertStringContainsString('більшою', $exception->getMessage());
            }
        }

        $this->assertSame('100.00', (string) $item->fresh()->price);
        $this->assertNull($item->fresh()->sale_price);
    }

    #[Test]
    public function stale_reviewed_offer_is_rejected_atomically(): void
    {
        [$product, $variant] = $this->manualProductWithVariant();
        $item = $this->priceItem($variant, '100.00');

        $reviewed = app(MasterOfferReadService::class)->state($variant);

        $item->update([
            'price' => '105.00',
        ]);

        $this->expectException(MasterOfferMutationException::class);
        $this->expectExceptionMessage('змінилася');

        try {
            app(MasterOfferMutationService::class)->setPrice(
                $this->actor,
                $this->workspace,
                $product,
                $variant,
                expectedItemId: $reviewed['expected_item_id'],
                expectedRegularNet: $reviewed['expected_regular_net'],
                expectedSaleNet: $reviewed['expected_sale_net'],
                sellNet: '110.00',
                compareAtNet: null,
            );
        } finally {
            $this->assertSame('105.00', (string) $item->fresh()->price);
            $this->assertNull($item->fresh()->sale_price);
        }
    }

    #[Test]
    public function newly_appeared_qty_one_row_makes_absence_based_form_stale(): void
    {
        [$product, $variant] = $this->manualProductWithVariant();
        $reviewed = app(MasterOfferReadService::class)->state($variant);
        $this->assertNull($reviewed['expected_item_id']);

        $this->priceItem($variant, '80.00');

        $this->expectException(MasterOfferMutationException::class);
        $this->expectExceptionMessage('змінилася');

        app(MasterOfferMutationService::class)->setPrice(
            $this->actor,
            $this->workspace,
            $product,
            $variant,
            expectedItemId: $reviewed['expected_item_id'],
            expectedRegularNet: $reviewed['expected_regular_net'],
            expectedSaleNet: $reviewed['expected_sale_net'],
            sellNet: '90.00',
            compareAtNet: null,
        );
    }

    #[Test]
    public function legacy_invalid_sale_row_is_read_only_and_not_silently_repaired(): void
    {
        [$product, $variant] = $this->manualProductWithVariant();
        $item = $this->priceItem($variant, '100.00', '120.00');

        $state = app(MasterOfferReadService::class)->state($variant);
        $this->assertFalse($state['editable']);
        $this->assertSame('legacy_invalid_sale_price', $state['state']);

        $this->expectException(MasterOfferMutationException::class);
        $this->expectExceptionMessage('потребує перевірки');

        try {
            app(MasterOfferMutationService::class)->setPrice(
                $this->actor,
                $this->workspace,
                $product,
                $variant,
                expectedItemId: $item->id,
                expectedRegularNet: '100.00',
                expectedSaleNet: '120.00',
                sellNet: '90.00',
                compareAtNet: '100.00',
            );
        } finally {
            $this->assertSame('100.00', (string) $item->fresh()->price);
            $this->assertSame('120.00', (string) $item->fresh()->sale_price);
        }
    }

    #[Test]
    public function scheduled_or_suspended_qty_one_row_is_read_only_in_master(): void
    {
        [, $variant] = $this->manualProductWithVariant();
        $item = $this->priceItem($variant, '100.00');
        $item->update(['valid_from' => now()->addDay()]);

        $state = app(MasterOfferReadService::class)->state($variant);
        $this->assertFalse($state['editable']);
        $this->assertSame('advanced_price_list_state', $state['state']);

        $item->update([
            'valid_from' => null,
            'status' => PriceListItemStatus::Suspended,
        ]);

        $state = app(MasterOfferReadService::class)->state($variant->fresh());
        $this->assertFalse($state['editable']);
        $this->assertSame('advanced_price_list_state', $state['state']);
    }

    #[Test]
    public function source_owned_product_is_fail_closed_for_master_price_write(): void
    {
        [$product, $variant] = $this->manualProductWithVariant();
        $product->update(['onec_guid' => '11111111-1111-4111-8111-111111111111']);

        $this->expectException(MasterOfferMutationException::class);
        $this->expectExceptionMessage('1С');

        app(MasterOfferMutationService::class)->setPrice(
            $this->actor,
            $this->workspace,
            $product->fresh(),
            $variant,
            expectedItemId: null,
            expectedRegularNet: null,
            expectedSaleNet: null,
            sellNet: '100.00',
            compareAtNet: null,
        );
    }

    #[Test]
    public function mutation_requires_manage_products_permission(): void
    {
        [$product, $variant] = $this->manualProductWithVariant();
        $other = User::factory()->create(['is_active' => true]);
        $this->makeWorkspaceMembership($this->workspace, $other);

        $this->expectException(AuthorizationException::class);

        app(MasterOfferMutationService::class)->setPrice(
            $other,
            $this->workspace,
            $product,
            $variant,
            expectedItemId: null,
            expectedRegularNet: null,
            expectedSaleNet: null,
            sellNet: '100.00',
            compareAtNet: null,
        );
    }

    /**
     * @return array{0: Product, 1: ProductVariant}
     */
    private function manualProductWithVariant(?string $basePriceCache = null): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'name' => 'Manual offer product',
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => null,
            'attributes' => [],
            'is_active' => true,
            'cost_price' => '40.00',
            'base_price_cache' => $basePriceCache,
        ]);

        return [$product, $variant];
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
