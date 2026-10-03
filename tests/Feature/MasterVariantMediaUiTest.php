<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Enums\MediaRole;
use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantMedia;
use App\Models\Workspace;
use App\Services\Catalog\ProductVariantStructureService;
use App\Services\Catalog\VariantMediaMutationService;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class MasterVariantMediaUiTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->workspace = $this->defaultWorkspace();
        $this->actor = User::factory()->create(['role' => UserRole::Admin]);
        $membership = $this->makeWorkspaceMembership($this->workspace, $this->actor);
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Variant media UI manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
            WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE,
        ]);
        $this->assignRoleToMembership($membership, $role);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function simple_product_hides_variant_media_authoring(): void
    {
        [$product] = $this->manualProduct();
        $this->attachCommonMedia($product, $this->asset('simple.jpg'));

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionHidden('assign_variant_media')
            ->assertDontSee('Медіа варіантів');
    }

    #[Test]
    public function variant_row_and_media_menu_use_the_same_assignment_action(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('media_color_row', 'Колір', [
            'black' => 'Чорний',
            'grey' => 'Сірий',
        ]);
        $variants = app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['grey'],
        );
        $this->attachCommonMedia($product, $this->asset('row-common.jpg'));

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible('assign_variant_media')
            ->assertSee('Фото')
            ->assertSee('Лише загальні')
            ->assertSeeHtml('assign_variant_media')
            ->mountAction('assign_variant_media', ['variant_id' => $variants[1]->id])
            ->assertActionDataSet(fn (array $data): bool => $data['variant_ids'] === [$variants[1]->id])
            ->assertMountedActionModalSee('Медіа варіантів')
            ->assertMountedActionModalSee('Додати до власних фото')
            ->assertMountedActionModalSee('Замінити власні фото')
            ->assertMountedActionModalSee('Зняти вибрані призначення');
    }

    #[Test]
    public function axis_group_assignment_expands_to_current_matching_variants_only(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('media_color_bulk', 'Колір', [
            'black' => 'Чорний',
            'grey' => 'Сірий',
        ]);
        $size = $this->selectVariantBinding('media_size_bulk', 'Розмір', [
            's' => 'S',
            'm' => 'M',
            'l' => 'L',
        ]);
        $service = app(ProductVariantStructureService::class);
        $variants = $service->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['grey'],
        );
        $service->addAxis($this->actor, $this->workspace, $product, $size->id, [
            (string) $variants[0]->id => 's',
            (string) $variants[1]->id => 's',
        ]);
        $blackM = $service->addVariant($this->actor, $this->workspace, $product, [
            $color->id => 'black',
            $size->id => 'm',
        ], 'BLACK-M');
        $greyM = $service->addVariant($this->actor, $this->workspace, $product, [
            $color->id => 'grey',
            $size->id => 'm',
        ], 'GREY-M');

        $asset = $this->asset('grey-family.jpg');
        $this->attachCommonMedia($product, $asset);

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction('assign_variant_media')
            ->assertMountedActionModalSee('Колір')
            ->assertMountedActionModalSee('Сірий · 2 вар.')
            ->assertMountedActionModalSee('Розмір')
            ->setActionData([
                'media_asset_ids' => [$asset->id],
                'variant_ids' => [],
                'axis_groups' => [
                    $color->id => ['grey'],
                    $size->id => [],
                ],
                'operation' => 'add',
                'make_primary' => true,
                'confirm_replace' => false,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $greyIds = collect([$variants[1]->id, $greyM->id])->sort()->values()->all();
        $blackIds = collect([$variants[0]->id, $blackM->id])->sort()->values()->all();

        $this->assertSame(
            $greyIds,
            VariantMedia::withoutWorkspaceScope()
                ->where('media_asset_id', $asset->id)
                ->where('role', MediaRole::Primary->value)
                ->orderBy('variant_id')
                ->pluck('variant_id')
                ->all(),
        );
        $this->assertFalse(
            VariantMedia::withoutWorkspaceScope()
                ->whereIn('variant_id', $blackIds)
                ->exists(),
        );

        $createdLater = $service->addVariant($this->actor, $this->workspace, $product, [
            $color->id => 'grey',
            $size->id => 'l',
        ], 'GREY-L-LATER');

        $this->assertFalse(
            VariantMedia::withoutWorkspaceScope()
                ->where('variant_id', $createdLater->id)
                ->exists(),
        );
    }

    #[Test]
    public function only_without_specific_filter_applies_to_axis_groups_and_replace_count(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('media_color_filtered', 'Колір', [
            'black' => 'Чорний',
            'grey' => 'Сірий',
        ]);
        $size = $this->selectVariantBinding('media_size_filtered', 'Розмір', [
            's' => 'S',
            'm' => 'M',
        ]);
        $structure = app(ProductVariantStructureService::class);
        $variants = $structure->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['grey'],
        );
        $structure->addAxis($this->actor, $this->workspace, $product, $size->id, [
            (string) $variants[0]->id => 's',
            (string) $variants[1]->id => 's',
        ]);
        $greyM = $structure->addVariant($this->actor, $this->workspace, $product, [
            $color->id => 'grey',
            $size->id => 'm',
        ], 'GREY-M-FILTERED');

        $old = $this->asset('filtered-old.jpg');
        $new = $this->asset('filtered-new.jpg');
        $this->attachCommonMedia($product, $new);

        app(VariantMediaMutationService::class)->assign(
            $this->actor,
            $this->workspace,
            $product,
            [$variants[1]->id],
            [$old->id],
            makeFirstSelectedPrimary: true,
        );

        $component = Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction('assign_variant_media')
            ->setActionData([
                'media_asset_ids' => [$new->id],
                'variant_ids' => [],
                'only_without_specific' => true,
                'axis_groups' => [
                    $color->id => ['grey'],
                    $size->id => [],
                ],
                'operation' => 'replace',
                'make_primary' => false,
                'confirm_replace' => false,
            ])
            ->assertMountedActionModalSee('Сірий · 1 вар.')
            ->assertMountedActionModalSee('1 варіантів');

        $component
            ->callMountedAction()
            ->assertHasActionErrors(['confirm_replace']);

        $component
            ->setActionData(['confirm_replace' => true])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertDatabaseHas('variant_media', [
            'variant_id' => $variants[1]->id,
            'media_asset_id' => $old->id,
        ]);
        $this->assertDatabaseMissing('variant_media', [
            'variant_id' => $variants[1]->id,
            'media_asset_id' => $new->id,
        ]);
        $this->assertDatabaseHas('variant_media', [
            'variant_id' => $greyM->id,
            'media_asset_id' => $new->id,
        ]);
    }

    #[Test]
    public function replace_requires_explicit_confirmation_with_target_count(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('media_color_replace', 'Колір', [
            'black' => 'Чорний',
            'grey' => 'Сірий',
        ]);
        $variants = app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['grey'],
        );

        $old = $this->asset('replace-old.jpg');
        $new = $this->asset('replace-new.jpg');
        $this->attachCommonMedia($product, $new);
        app(VariantMediaMutationService::class)->assign(
            $this->actor,
            $this->workspace,
            $product,
            $variants->pluck('id')->all(),
            [$old->id],
            makeFirstSelectedPrimary: true,
        );

        $component = Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction('assign_variant_media')
            ->setActionData([
                'media_asset_ids' => [$new->id],
                'variant_ids' => $variants->pluck('id')->all(),
                'axis_groups' => [],
                'operation' => 'replace',
                'make_primary' => false,
                'confirm_replace' => false,
            ])
            ->assertMountedActionModalSee('2 варіантів');

        $component
            ->callMountedAction()
            ->assertHasActionErrors(['confirm_replace']);

        $this->assertSame(
            2,
            VariantMedia::withoutWorkspaceScope()
                ->where('media_asset_id', $old->id)
                ->count(),
        );
    }

    #[Test]
    public function variant_table_exposes_specific_media_without_silent_primary(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('media_color_coverage', 'Колір', [
            'black' => 'Чорний',
            'grey' => 'Сірий',
        ]);
        $variants = app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['grey'],
        );
        $first = $this->asset('coverage-1.jpg');
        $second = $this->asset('coverage-2.jpg');

        app(VariantMediaMutationService::class)->assign(
            $this->actor,
            $this->workspace,
            $product,
            [$variants[0]->id],
            [$first->id, $second->id],
        );

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Власні · 2 · без головного')
            ->assertSee('Немає фото');
    }

    /** @return array{Product,ProductVariant} */
    private function manualProduct(): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Variant media UI product',
            'is_active' => true,
        ]);
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => 'BASE-SKU',
            'barcode_ean' => 'BASE-GTIN',
            'attributes' => [],
            'is_active' => true,
        ]);

        return [$product, $variant];
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
                'options' => collect($options)->map(fn (string $optionLabel, string $optionCode): array => [
                    'code' => $optionCode,
                    'labels' => ['uk' => $optionLabel],
                ])->values()->all(),
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

    private function asset(string $filename): MediaAsset
    {
        return MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'parent_media_asset_id' => null,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/'.$filename,
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
    }

    private function attachCommonMedia(Product $product, MediaAsset $asset): void
    {
        $existingCount = ProductMedia::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->whereNull('locale')
            ->count();

        ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $product->workspace_id,
            'product_id' => $product->id,
            'media_asset_id' => $asset->id,
            'role' => $existingCount === 0 ? MediaRole::Primary : MediaRole::Gallery,
            'sort_order' => $existingCount,
            'locale' => null,
        ]);
    }
}
