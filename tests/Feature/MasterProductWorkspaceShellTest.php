<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\AttributeGroup;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductFieldValue;
use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductTypeGroupPlacement;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Services\Catalog\ProductWorkspaceSummaryService;
use App\Services\ProductStructure\ProductStructureMutationService;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MasterProductWorkspaceShellTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->where('is_default', true)->sole();
        $this->admin = User::query()->create([
            'name' => 'Workspace shell admin',
            'email' => 'workspace-shell@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function edit_page_renders_the_visible_master_product_workspace_sections(): void
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'WORKSPACE-001',
            'name' => 'Workspace stroller',
            'brand' => 'BabyPark',
            'description' => '<p>Workspace description</p>',
            'images' => [
                'https://example.test/media/main.jpg',
                'https://example.test/media/second.jpg',
            ],
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'WORKSPACE-001',
            'attributes' => [],
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Основна інформація')
            ->assertSee('Медіа')
            ->assertSee('Ціна')
            ->assertSee('Залишки')
            ->assertSee('Доставка та фізичні дані')
            ->assertSee('Варіанти')
            ->assertSee('Характеристики')
            ->assertSee('SEO та пошук')
            ->assertSee('Організація')
            ->assertSee('Якість даних')
            ->assertSee('Канали публікації')
            ->assertSee('Потребує уваги')
            ->assertSee('Імпортувати Excel / CSV')
            ->assertSee('Заповнити з файлу')
            ->assertSee('Покращити')
            ->assertSee('Видалити фон')
            ->assertSee('Підготувати для каналу')
            ->assertSee('Редагувати ціни')
            ->assertSee('Редагувати залишки')
            ->assertDontSee('Медіа варіантів')
            ->assertDontSee('Назначити варіантам')
            ->assertSee('Заповнити характеристики з файлу')
            ->assertSee('Чекає на підключення')
            ->assertSee('Отримати ключові слова')
            ->assertSee('Створити опис з AI')
            ->assertSee('Аналіз пошуку')
            ->assertSee('Related / Upsell / Cross-sell')
            ->assertSee('Master Product · 1С · SKU WORKSPACE-001')
            ->assertSee('Простий товар')
            ->assertSee('2 медіа · поточний Master-набір')
            ->assertSee('Інформаційно · не є готовністю конкретного каналу.');
    }

    #[Test]
    public function pending_capability_modal_is_visible_and_does_not_mutate_product(): void
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'sku' => 'PENDING-UI-001',
            'name' => 'Pending capability product',
            'description' => '<p>Original description</p>',
            'meta_title' => 'Original SEO title',
            'meta_description' => 'Original SEO description',
            'url' => 'https://example.test/original',
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => 'PENDING-UI-001',
            'attributes' => [],
            'is_active' => true,
        ]);

        $before = $product->fresh()->only([
            'name',
            'description',
            'meta_title',
            'meta_description',
            'url',
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction(
                TestAction::make('import_spreadsheet')
                    ->schemaComponent('basic_capability_actions'),
            )
            ->assertActionMounted(
                TestAction::make('import_spreadsheet')
                    ->schemaComponent('basic_capability_actions'),
            )
            ->assertSee('Чекає на підключення')
            ->unmountAction();

        $this->assertSame($before, $product->fresh()->only(array_keys($before)));
    }

    #[Test]
    public function deferred_seo_fields_are_not_saved_from_master_workspace(): void
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'sku' => 'SEO-DEFERRED-001',
            'name' => 'SEO deferred product',
            'description' => '<p>Description</p>',
            'meta_title' => 'Keep title',
            'meta_description' => 'Keep description',
            'url' => 'https://example.test/keep',
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => 'SEO-DEFERRED-001',
            'attributes' => [],
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'name' => 'SEO deferred product updated',
                'meta_title' => 'Must not save',
                'meta_description' => 'Must not save',
                'url' => 'https://example.test/must-not-save',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $product->fresh();

        $this->assertSame('SEO deferred product updated', $fresh->name);
        $this->assertSame('Keep title', $fresh->meta_title);
        $this->assertSame('Keep description', $fresh->meta_description);
        $this->assertSame('https://example.test/keep', $fresh->url);
    }

    #[Test]
    public function basic_quality_is_informational_and_tracks_only_visible_master_signals(): void
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Draft product',
            'brand' => null,
            'description' => '<p>Draft description</p>',
            'images' => [],
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => null,
            'attributes' => [],
            'is_active' => true,
        ]);

        $summary = app(ProductWorkspaceSummaryService::class)->basicCompleteness($product);

        $this->assertSame(2, $summary['filled']);
        $this->assertSame(5, $summary['total']);
        $this->assertSame(40, $summary['percentage']);
        $this->assertSame(['Категорія', 'Бренд', 'Медіа'], $summary['missing']);
    }

    #[Test]
    public function workspace_characteristic_groups_show_real_governed_completeness_and_missing_field_labels(): void
    {
        $type = ProductType::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => 'workspace-test-type',
            'localized_labels' => ['uk' => 'Тестовий тип'],
            'status' => 'active',
            'is_default' => false,
            'structure_revision' => 1,
        ]);
        $group = AttributeGroup::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => 'materials',
            'localized_labels' => ['uk' => 'Матеріали'],
            'status' => 'active',
        ]);
        $groupPlacement = ProductTypeGroupPlacement::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_type_id' => $type->id,
            'attribute_group_id' => $group->id,
            'sort_order' => 100,
            'is_optional' => false,
            'default_active' => true,
        ]);
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => 'fabric',
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Тканина'],
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);
        $binding = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 100,
            'status' => AttributeStatus::Active,
        ]);
        ProductTypeFieldPlacement::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_type_id' => $type->id,
            'product_type_group_placement_id' => $groupPlacement->id,
            'field_binding_id' => $binding->id,
            'sort_order' => 100,
            'required_for_completeness' => true,
        ]);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Structured product',
            'is_active' => true,
        ]);
        $product->forceFill(['product_type_id' => $type->id])->save();

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => null,
            'attributes' => [],
            'is_active' => true,
        ]);

        $missing = app(ProductWorkspaceSummaryService::class)->attributeGroups($product);

        $this->assertSame('Матеріали', $missing[0]['label']);
        $this->assertSame(0, $missing[0]['filled']);
        $this->assertSame(1, $missing[0]['required']);
        $this->assertSame(0, $missing[0]['percentage']);
        $this->assertSame(['Тканина'], $missing[0]['missing']);

        ProductFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $binding->id,
            'value_text' => 'cotton',
        ]);

        $complete = app(ProductWorkspaceSummaryService::class)->attributeGroups($product);

        $this->assertSame(1, $complete[0]['filled']);
        $this->assertSame(100, $complete[0]['percentage']);
        $this->assertSame([], $complete[0]['missing']);
    }

    #[Test]
    public function product_type_header_action_uses_governed_mutation_service(): void
    {
        $this->grantWorkspacePermission(WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Type change product',
            'is_active' => true,
        ]);
        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => null,
            'attributes' => [],
            'is_active' => true,
        ]);
        $target = ProductType::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => 'alternate-type',
            'localized_labels' => ['uk' => 'Інший тип'],
            'status' => 'active',
            'is_default' => false,
            'structure_revision' => 1,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('change_product_type', ['product_type_id' => $target->id])
            ->assertNotified();

        $this->assertSame((string) $target->id, (string) $product->fresh()->product_type_id);
    }

    #[Test]
    public function product_type_confirmation_rejects_structure_changes_after_reviewed_preview(): void
    {
        $this->grantWorkspacePermission(WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'STALE-PREVIEW-P',
            'name' => 'Stale preview product',
            'is_active' => true,
        ]);
        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'STALE-PREVIEW-V',
            'attributes' => [],
            'is_active' => true,
        ]);
        $target = ProductType::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => 'stale-preview-target',
            'localized_labels' => ['uk' => 'Цільовий тип'],
            'status' => 'active',
            'is_default' => false,
            'structure_revision' => 1,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction('change_product_type')
            ->setActionData(['product_type_id' => $target->id])
            ->assertActionDataSet(fn (array $data): bool => filled($data['reviewed_impact_token'] ?? null));

        $structure = app(ProductStructureMutationService::class);
        $group = $structure->createAttributeGroup(
            $this->admin,
            $this->workspace,
            'late-preview-group',
            ['uk' => 'Пізня група'],
        );
        $structure->putGroupPlacement(
            $this->admin,
            $this->workspace,
            $target,
            $group,
            100,
            false,
            true,
        );

        $component
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertActionNotMounted();

        $this->assertNotSame((string) $target->id, (string) $product->fresh()->product_type_id);
    }

    #[Test]
    public function variant_summary_keeps_a_single_internal_variant_visually_simple(): void
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Simple product',
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => null,
            'attributes' => [],
            'is_active' => true,
        ]);

        $summary = app(ProductWorkspaceSummaryService::class)->variants($product);

        $this->assertSame(1, $summary['count']);
        $this->assertSame('Простий товар', $summary['label']);
        $this->assertSame([], $summary['skus']);
    }

    private function grantWorkspacePermission(string $code): void
    {
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $membership = WorkspaceUser::query()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->admin->id,
            'is_active' => true,
        ]);
        $role = WorkspaceRole::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Workspace structure editor',
        ]);
        $permission = WorkspacePermission::query()->where('code', $code)->sole();

        DB::table('workspace_user_roles')->insert([
            'workspace_id' => $this->workspace->id,
            'workspace_user_id' => $membership->id,
            'workspace_role_id' => $role->id,
        ]);
        DB::table('workspace_role_permissions')->insert([
            'workspace_id' => $this->workspace->id,
            'workspace_role_id' => $role->id,
            'workspace_permission_id' => $permission->id,
        ]);
    }
}
