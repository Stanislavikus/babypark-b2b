<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Enums\InventoryRecordSourceType;
use App\Enums\MediaRole;
use App\Enums\PriceListStatus;
use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Category;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Tag;
use App\Models\User;
use App\Models\VariantMedia;
use App\Models\Workspace;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class MasterProductCardJourneyTest extends TestCase
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
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Master card journey editor', [
            WorkspacePermissions::MANAGE_PRODUCTS,
            WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE,
            WorkspacePermissions::MANAGE_PRODUCT_COST,
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

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function merchant_can_prepare_a_simple_product_across_the_real_master_card_surfaces(): void
    {
        Storage::fake('public');

        $root = Category::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Дитячі товари',
        ]);
        $category = Category::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Коляски',
            'parent_id' => $root->id,
        ]);
        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'journey',
        ]);

        Livewire::actingAs($this->actor)
            ->test(CreateProduct::class)
            ->fillForm([
                'name' => 'Journey simple stroller',
                'sku' => 'JOURNEY-SIMPLE',
                'brand' => 'BabyPark',
                'description' => '<p>Initial merchant draft</p>',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('name', 'Journey simple stroller')
            ->sole();
        $variant = ProductVariant::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->sole();

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'category_id' => $category->id,
                'brand' => 'BabyPark Journey',
                'merchant_type' => 'Прогулянкова коляска',
                'tags' => [$tag->id],
                'net_weight' => '5.250',
                'gross_weight' => '6.100',
                'width_mm' => 610,
                'height_mm' => 1040,
                'depth_mm' => 870,
                'package_quantity' => 1,
                'package_type' => 'коробка',
                'lead_time_days' => 4,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertActionVisible('add_media')
            ->assertSee('Простий товар');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('add_media', [
                'files' => [UploadedFile::fake()->image('simple-journey.jpg', 1200, 900)],
            ])
            ->assertNotified();

        $offerAction = TestAction::make('edit_offer')->schemaComponent('offer_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction($offerAction)
            ->assertFormFieldHidden('variant_id')
            ->assertFormFieldVisible('cost_net')
            ->unmountAction()
            ->callAction($offerAction, [
                'variant_id' => (string) $variant->id,
                'expected_item_id' => null,
                'expected_regular_net' => null,
                'expected_sale_net' => null,
                'expected_cost_net' => null,
                'write_cost' => true,
                'effective_vat_rate' => '20.00',
                'sell_net' => '90.00',
                'compare_at_net' => '120.00',
                'cost_net' => '50.00',
            ])
            ->assertNotified('Ціну оновлено');

        $inventoryAction = TestAction::make('edit_inventory')->schemaComponent('inventory_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction($inventoryAction)
            ->assertFormFieldHidden('variant_id')
            ->unmountAction()
            ->callAction($inventoryAction, [
                'variant_id' => (string) $variant->id,
                'expected_quantity' => 0,
                'new_quantity' => 7,
                'reason' => 'Initial count',
            ])
            ->assertNotified('Залишок оновлено');

        $fresh = $product->fresh();

        $this->assertSame($category->id, $fresh->category_id);
        $this->assertSame('BabyPark Journey', $fresh->brand);
        $this->assertSame('Прогулянкова коляска', $fresh->merchant_type);
        $this->assertTrue($fresh->tags->contains($tag));
        $this->assertSame('5.250', (string) $fresh->net_weight);
        $this->assertSame(610, (int) $fresh->width_mm);
        $this->assertSame(1, ProductMedia::withoutWorkspaceScope()->where('product_id', $product->id)->count());

        $priceItem = PriceListItem::withoutWorkspaceScope()
            ->where('price_list_id', $this->defaultPriceList->id)
            ->where('product_variant_id', $variant->id)
            ->where('quantity_min', 1)
            ->sole();

        $this->assertSame('120.00', (string) $priceItem->price);
        $this->assertSame('90.00', (string) $priceItem->sale_price);
        $this->assertSame('50.00', (string) $variant->fresh()->cost_price);
        $this->assertSame(7, $variant->fresh()->available_quantity_cache);
        $this->assertSame(
            7,
            Stock::withoutWorkspaceScope()->where('variant_id', $variant->id)->sole()->quantity,
        );
        $this->assertDatabaseHas('inventory_records', [
            'product_variant_id' => $variant->id,
            'source_type' => InventoryRecordSourceType::ManualAdjustment->value,
            'quantity_change' => 7,
            'resulting_quantity' => 7,
        ]);
    }

    #[Test]
    public function merchant_can_promote_to_variants_and_target_offer_inventory_and_media_to_one_variant(): void
    {
        Storage::fake('public');

        Livewire::actingAs($this->actor)
            ->test(CreateProduct::class)
            ->fillForm([
                'name' => 'Journey configurable stroller',
                'sku' => 'JOURNEY-CONF',
                'brand' => 'BabyPark',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('name', 'Journey configurable stroller')
            ->sole();
        $originalVariant = ProductVariant::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->sole();
        $originalVariantId = $originalVariant->id;

        $color = $this->selectVariantBinding('journey_color', 'Колір', [
            'black' => 'Чорний',
            'white' => 'Білий',
        ]);

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible('promote_variants')
            ->callAction('promote_variants', [
                'axis_binding_id' => $color->id,
                'existing_value' => 'black',
                'additional_values' => ['white'],
            ])
            ->assertNotified()
            ->assertSee('Чорний')
            ->assertSee('Білий');

        $variants = ProductVariant::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $variants);
        $this->assertTrue($variants->contains(fn (ProductVariant $variant): bool => $variant->id === $originalVariantId));

        $target = $variants->first(fn (ProductVariant $variant): bool => $variant->id !== $originalVariantId);
        $this->assertInstanceOf(ProductVariant::class, $target);

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('add_media', [
                'files' => [UploadedFile::fake()->image('configurable-journey.jpg', 1200, 900)],
            ])
            ->assertNotified()
            ->assertActionVisible('assign_variant_media');

        $media = ProductMedia::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->where('role', MediaRole::Primary->value)
            ->sole();

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('assign_variant_media', [
                'media_asset_ids' => [$media->media_asset_id],
                'variant_ids' => [$target->id],
                'only_without_specific' => false,
                'axis_groups' => [],
                'operation' => 'add',
                'make_primary' => true,
                'confirm_replace' => false,
            ])
            ->assertNotified('Фото призначено варіантам');

        $offerAction = TestAction::make('edit_offer')->schemaComponent('offer_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction($offerAction, [
                'variant_id' => (string) $target->id,
                'expected_item_id' => null,
                'expected_regular_net' => null,
                'expected_sale_net' => null,
                'expected_cost_net' => null,
                'write_cost' => true,
                'effective_vat_rate' => '20.00',
                'sell_net' => '95.00',
                'compare_at_net' => '115.00',
                'cost_net' => '52.00',
            ])
            ->assertNotified('Ціну оновлено');

        $inventoryAction = TestAction::make('edit_inventory')->schemaComponent('inventory_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction($inventoryAction, [
                'variant_id' => (string) $target->id,
                'expected_quantity' => 0,
                'new_quantity' => 5,
                'reason' => 'Variant initial count',
            ])
            ->assertNotified('Залишок оновлено');

        $original = ProductVariant::withoutWorkspaceScope()->findOrFail($originalVariantId);
        $target->refresh();

        $this->assertFalse(
            PriceListItem::withoutWorkspaceScope()
                ->where('price_list_id', $this->defaultPriceList->id)
                ->where('product_variant_id', $original->id)
                ->exists(),
        );
        $this->assertFalse(Stock::withoutWorkspaceScope()->where('variant_id', $original->id)->exists());
        $this->assertFalse(VariantMedia::withoutWorkspaceScope()->where('variant_id', $original->id)->exists());

        $targetPrice = PriceListItem::withoutWorkspaceScope()
            ->where('price_list_id', $this->defaultPriceList->id)
            ->where('product_variant_id', $target->id)
            ->where('quantity_min', 1)
            ->sole();

        $this->assertSame('115.00', (string) $targetPrice->price);
        $this->assertSame('95.00', (string) $targetPrice->sale_price);
        $this->assertSame('52.00', (string) $target->cost_price);
        $this->assertSame(5, $target->available_quantity_cache);
        $this->assertSame(
            5,
            Stock::withoutWorkspaceScope()->where('variant_id', $target->id)->sole()->quantity,
        );
        $this->assertDatabaseHas('variant_media', [
            'variant_id' => $target->id,
            'media_asset_id' => $media->media_asset_id,
            'role' => MediaRole::Primary->value,
        ]);
    }

    /** @param array<string,string> $options */
    private function selectVariantBinding(string $code, string $label, array $options): FieldBinding
    {
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => $code,
            'data_type' => AttributeDataType::Select,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => $label],
            'validation_rules' => [
                'options' => collect($options)->map(
                    fn (string $optionLabel, string $optionCode): array => [
                        'code' => $optionCode,
                        'labels' => ['uk' => $optionLabel],
                    ],
                )->values()->all(),
            ],
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        return FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::ProductVariant,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => true,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 100,
            'status' => AttributeStatus::Active,
        ]);
    }
}
