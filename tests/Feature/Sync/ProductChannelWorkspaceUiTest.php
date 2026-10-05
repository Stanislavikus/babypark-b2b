<?php

namespace Tests\Feature\Sync;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Filament\Pages\Sync\ManageAdobeProductsChannel;
use App\Filament\Pages\Sync\ManageAdobeProductsExportPreview;
use App\Filament\Pages\Sync\ManageAdobeRemoteCatalog;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetOverride;
use App\Models\AdobeProductCategory;
use App\Models\AdobeProductCategoryOverride;
use App\Models\AdobeProductTypeAttributeSetDefault;
use App\Models\Category;
use App\Models\ConnectorCategoryMapping;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Services\Sync\AdobeProductClassificationReadService;
use App\Services\Sync\ProductChannelSelectionService;
use App\Services\Sync\ProductMagentoClassificationEditor;
use App\Services\Sync\SyncDataSetupLandingService;
use App\Services\Sync\SyncProductSelectionService;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class ProductChannelWorkspaceUiTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->configureAdobeProductsExportSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Export],
        ]);

        $this->workspace = $this->defaultWorkspace();
        $this->actor = $this->createStaffUser(UserRole::Admin);
        $this->grantExactWorkspacePermissions($this->workspace, $this->actor, [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function empty_magento_channel_explains_selection_before_preview(): void
    {
        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);
        $this->createProduct('CHANNEL-EMPTY-A');
        $this->createProduct('CHANNEL-EMPTY-B');

        Livewire::actingAs($this->actor)
            ->test(ManageAdobeProductsChannel::class, ['account' => $account->id])
            ->assertSet('selectedProductCount', 0)
            ->assertSet('masterProductCount', 2)
            ->assertSee('data-testid="product-channel-empty-selection"', false)
            ->assertSee(__('product_channels.channel.empty_title'))
            ->assertSee(__('product_channels.channel.select_products'))
            ->assertSee('data-testid="product-workbench-shell"', false)
            ->assertSee('data-testid="product-workbench-tab-overview"', false)
            ->assertSee('data-testid="product-workbench-tab-publication"', false)
            ->assertDontSee('data-testid="product-channel-open-master-catalog"', false)
            ->assertDontSee('data-testid="product-channel-open-preview"', false);
    }

    #[Test]
    public function channel_entry_url_lands_on_overview_not_publication(): void
    {
        $account = $this->createConnectorAccount();

        $target = collect(app(SyncDataSetupLandingService::class)
            ->listLandingTargets($this->actor, $this->workspace))
            ->firstWhere('accountId', $account->id);

        $this->assertNotNull($target);
        $this->assertSame(
            ManageAdobeRemoteCatalog::getUrl(['account' => $account->id]),
            $target->channelUrl,
        );
        $this->assertNotSame(
            ManageAdobeProductsChannel::getUrl(['account' => $account->id]),
            $target->channelUrl,
        );
        $this->assertTrue(Filament::getPanel('admin')->isSidebarCollapsibleOnDesktop());

        $sidebarStyles = File::get(resource_path('views/filament/partials/table-toolbar-overrides.blade.php'));
        $this->assertStringContainsString('fi-sidebar-open-collapse-sidebar-btn', $sidebarStyles);
        $this->assertStringContainsString('fi-sidebar-close-collapse-sidebar-btn', $sidebarStyles);
        $this->assertStringContainsString("content: '☰'", $sidebarStyles);
    }

    #[Test]
    public function empty_channel_creates_configuration_only_after_explicit_select_products_action(): void
    {
        $account = $this->createConnectorAccount();

        $this->assertSame(0, SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('connector_account_id', $account->id)
            ->count());

        $component = Livewire::actingAs($this->actor)
            ->test(ManageAdobeProductsChannel::class, ['account' => $account->id])
            ->assertSet('configurationId', null)
            ->call('selectProducts');

        $configuration = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('connector_account_id', $account->id)
            ->where('data_domain', SyncDataDomain::Products)
            ->sole();

        $component->assertRedirect(ProductResource::getUrl('index', [
            'channel' => $configuration->id,
        ]));
    }

    #[Test]
    public function channel_workspace_shows_only_selected_master_products(): void
    {
        $account = $this->createConnectorAccount();
        $configuration = $this->createProductsExportConfiguration($account->id);
        $selected = $this->createProduct('CHANNEL-SELECTED');
        $unselected = $this->createProduct('CHANNEL-OTHER');

        app(ProductChannelSelectionService::class)->add(
            $this->actor,
            $this->workspace,
            $configuration->id,
            [$selected->id],
        );

        Livewire::actingAs($this->actor)
            ->test(ManageAdobeProductsChannel::class, ['account' => $account->id])
            ->assertSet('selectedProductCount', 1)
            ->assertSet('masterProductCount', 2)
            ->assertSee($selected->name)
            ->assertSee(__('product_channels.workbench.publication.not_in_magento'))
            ->assertDontSee($unselected->name)
            ->assertSee('data-testid="product-workbench-tab-overview"', false)
            ->assertSee('data-testid="product-workbench-tab-publication"', false);
    }

    #[Test]
    public function selected_master_product_exposes_direct_magento_v1_workbench_link(): void
    {
        $this->grantExactWorkspacePermissions($this->workspace, $this->actor, [
            WorkspacePermissions::MANAGE_PRODUCTS,
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        ]);

        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);
        $configuration = $this->createProductsExportConfiguration($account->id);
        $product = $this->createProduct('MASTER-TO-MAGENTO-V1');

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'MASTER-TO-MAGENTO-V1',
            'attributes' => [],
            'is_active' => true,
        ]);

        app(ProductChannelSelectionService::class)->add(
            $this->actor,
            $this->workspace,
            $configuration->id,
            [$product->id],
        );

        $action = TestAction::make('open_magento_v1')
            ->schemaComponent('channel_capability_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Відкрити Magento V1')
            ->assertSee('Потрібне налаштування')
            ->assertSee('Не вибрано категорію Magento.')
            ->assertSee('Не вибрано набір атрибутів Magento.')
            ->assertSee('Для Magento тут показано лише стан класифікації.')
            ->assertActionVisible($action)
            ->assertActionHasUrl(
                $action,
                ManageAdobeProductsChannel::getUrl(['account' => $account->id]),
            )
            ->assertActionShouldOpenUrlInNewTab($action);
    }

    #[Test]
    public function master_card_magento_editor_overrides_and_resets_to_automatic_classification_without_changing_master(): void
    {
        $this->grantExactWorkspacePermissions($this->workspace, $this->actor, [
            WorkspacePermissions::MANAGE_PRODUCTS,
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        ]);

        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);
        $configuration = $this->createProductsExportConfiguration($account->id);

        $masterCategory = Category::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Master strollers',
        ]);
        $product = $this->createProduct('MASTER-MAGENTO-EDITOR', [
            'category_id' => $masterCategory->id,
        ]);
        $originalProductTypeId = $product->product_type_id;

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => 'MASTER-MAGENTO-EDITOR',
            'attributes' => [],
            'is_active' => true,
        ]);

        $source = app(ConnectorDiscoverySourceResolver::class)->resolve($account);
        $setA = AdobeProductAttributeSet::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'provider_attribute_set_id' => 4,
            'name' => 'Default',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);
        $setB = AdobeProductAttributeSet::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'provider_attribute_set_id' => 9,
            'name' => 'Strollers',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);

        foreach ([
            ['id' => '12', 'name' => 'Strollers'],
            ['id' => '13', 'name' => 'Travel strollers'],
        ] as $category) {
            AdobeProductCategory::withoutWorkspaceScope()->create([
                'workspace_id' => $this->workspace->id,
                'connector_account_id' => $account->id,
                'external_category_id' => $category['id'],
                'parent_external_category_id' => '2',
                'name' => $category['name'],
                'provider_path' => '1/2/'.$category['id'],
                'breadcrumb' => 'Baby > '.$category['name'],
                'level' => 2,
                'position' => 1,
                'is_active' => true,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'missing_since' => null,
            ]);
        }

        ConnectorCategoryMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'category_id' => $masterCategory->id,
            'external_category_id' => '12',
        ]);

        AdobeProductTypeAttributeSetDefault::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'product_type_id' => $product->product_type_id,
            'adobe_product_attribute_set_id' => $setA->id,
        ]);

        app(ProductChannelSelectionService::class)->add(
            $this->actor,
            $this->workspace,
            $configuration->id,
            [$product->id],
        );

        $action = TestAction::make('configure_magento')
            ->schemaComponent('channel_capability_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible($action)
            ->mountAction($action)
            ->assertActionDataSet(fn (array $data): bool => (
                ($data['account_id'] ?? null) === $account->id
                && ($data['category_mode'] ?? null) === 'automatic'
                && ($data['category_ids'] ?? null) === ['12']
                && ($data['attribute_set_mode'] ?? null) === 'automatic'
                && ($data['attribute_set_id'] ?? null) === $setA->id
            ))
            ->unmountAction()
            ->callAction($action, [
                'account_id' => $account->id,
                'category_mode' => 'override',
                'category_ids' => ['13'],
                'attribute_set_mode' => 'override',
                'attribute_set_id' => $setB->id,
            ])
            ->assertNotified('Magento класифікацію збережено');

        $this->assertDatabaseHas('adobe_product_category_overrides', [
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_category_id' => '13',
        ]);
        $this->assertDatabaseHas('adobe_product_attribute_set_overrides', [
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'adobe_product_attribute_set_id' => $setB->id,
        ]);

        $override = app(AdobeProductClassificationReadService::class)->resolve($account, $product->fresh());
        $this->assertSame(['13'], $override->externalCategoryIds);
        $this->assertSame('product_override', $override->categorySource);
        $this->assertSame($setB->id, $override->adobeProductAttributeSetId);
        $this->assertSame('product_override', $override->attributeSetSource);

        $product->refresh();
        $this->assertSame($masterCategory->id, $product->category_id);
        $this->assertSame($originalProductTypeId, $product->product_type_id);

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction($action, [
                'account_id' => $account->id,
                'category_mode' => 'automatic',
                'category_ids' => ['13'],
                'attribute_set_mode' => 'automatic',
                'attribute_set_id' => $setB->id,
            ])
            ->assertNotified('Magento класифікацію збережено');

        $this->assertFalse(AdobeProductCategoryOverride::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('connector_account_id', $account->id)
            ->where('product_id', $product->id)
            ->exists());
        $this->assertFalse(AdobeProductAttributeSetOverride::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('connector_account_id', $account->id)
            ->where('product_id', $product->id)
            ->exists());

        $automatic = app(AdobeProductClassificationReadService::class)->resolve($account, $product->fresh());
        $this->assertSame(['12'], $automatic->externalCategoryIds);
        $this->assertSame('category_mapping', $automatic->categorySource);
        $this->assertSame($setA->id, $automatic->adobeProductAttributeSetId);
        $this->assertSame('product_type_default', $automatic->attributeSetSource);

        $product->refresh();
        $this->assertSame($masterCategory->id, $product->category_id);
        $this->assertSame($originalProductTypeId, $product->product_type_id);
    }

    #[Test]
    public function trusted_magento_product_exposes_remote_attribute_set_as_read_only_and_tampered_override_rolls_back(): void
    {
        $this->grantExactWorkspacePermissions($this->workspace, $this->actor, [
            WorkspacePermissions::MANAGE_PRODUCTS,
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        ]);

        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);
        $configuration = $this->createProductsExportConfiguration($account->id);
        $product = $this->createProduct('MASTER-MAGENTO-TRUSTED');

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => 'MASTER-MAGENTO-TRUSTED',
            'attributes' => [],
            'is_active' => true,
        ]);

        $source = app(ConnectorDiscoverySourceResolver::class)->resolve($account);
        $set = AdobeProductAttributeSet::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'provider_attribute_set_id' => 9,
            'name' => 'Strollers',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);
        AdobeProductCategory::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'external_category_id' => '13',
            'parent_external_category_id' => '2',
            'name' => 'Travel strollers',
            'provider_path' => '1/2/13',
            'breadcrumb' => 'Baby > Travel strollers',
            'level' => 2,
            'position' => 1,
            'is_active' => true,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);

        app(ProductChannelSelectionService::class)->add(
            $this->actor,
            $this->workspace,
            $configuration->id,
            [$product->id],
        );

        $membership = WorkspaceUser::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('user_id', $this->actor->id)
            ->firstOrFail();

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'external_identifier' => 'MASTER-MAGENTO-TRUSTED',
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => '777',
            'established_by_workspace_user_id' => $membership->id,
            'established_at' => now(),
        ]);

        $action = TestAction::make('configure_magento')
            ->schemaComponent('channel_capability_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction($action)
            ->assertActionDataSet(fn (array $data): bool => (
                ($data['account_id'] ?? null) === $account->id
                && ($data['attribute_set_mode'] ?? null) === 'observed_remote'
            ))
            ->unmountAction()
            ->callAction($action, [
                'account_id' => $account->id,
                'category_mode' => 'override',
                'category_ids' => ['13'],
                'attribute_set_mode' => 'override',
                'attribute_set_id' => $set->id,
            ])
            ->assertHasActionErrors(['attribute_set_mode']);

        $this->assertDatabaseMissing('adobe_product_category_overrides', [
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
        ]);
        $this->assertDatabaseMissing('adobe_product_attribute_set_overrides', [
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
        ]);
    }

    #[Test]
    public function magento_editor_rechecks_current_selection_instead_of_trusting_a_stale_loaded_relation(): void
    {
        $this->grantExactWorkspacePermissions($this->workspace, $this->actor, [
            WorkspacePermissions::MANAGE_PRODUCTS,
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);

        $account = $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
        ]);
        $configuration = $this->createProductsExportConfiguration($account->id);
        $product = $this->createProduct('MASTER-MAGENTO-STALE-SELECTION');

        app(ProductChannelSelectionService::class)->add(
            $this->actor,
            $this->workspace,
            $configuration->id,
            [$product->id],
        );

        $product->load('syncChannelSelections.syncConfiguration.connectorAccount.connectorDefinition');

        $editor = app(ProductMagentoClassificationEditor::class);
        $this->assertArrayHasKey($account->id, $editor->accountOptions($product));

        app(ProductChannelSelectionService::class)->remove(
            $this->actor,
            $this->workspace,
            $configuration->id,
            [$product->id],
        );

        $this->assertSame([], $editor->accountOptions($product));

        $this->expectException(AuthorizationException::class);
        $editor->formState($product, $account->id);
    }

    #[Test]
    public function selected_magento_product_shows_ready_classification_without_claiming_publication_readiness(): void
    {
        $this->grantExactWorkspacePermissions($this->workspace, $this->actor, [
            WorkspacePermissions::MANAGE_PRODUCTS,
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        ]);

        $account = $this->createConnectorAccount();
        $configuration = $this->createProductsExportConfiguration($account->id);
        $category = Category::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Ready category',
        ]);
        $product = $this->createProduct('MASTER-READY-CLASSIFICATION', [
            'category_id' => $category->id,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => 'MASTER-READY-CLASSIFICATION',
            'attributes' => [],
            'is_active' => true,
        ]);

        $source = app(ConnectorDiscoverySourceResolver::class)->resolve($account);
        $attributeSet = AdobeProductAttributeSet::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'provider_attribute_set_id' => 4,
            'name' => 'Default',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);

        ConnectorCategoryMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'category_id' => $category->id,
            'external_category_id' => '12',
        ]);

        AdobeProductTypeAttributeSetDefault::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'product_type_id' => $product->product_type_id,
            'adobe_product_attribute_set_id' => $attributeSet->id,
        ]);

        app(ProductChannelSelectionService::class)->add(
            $this->actor,
            $this->workspace,
            $configuration->id,
            [$product->id],
        );

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Класифікація готова')
            ->assertSee('Категорія та набір атрибутів Magento визначені.')
            ->assertSee('Готовність до публікації перевіряється в каналі окремо.')
            ->assertDontSee('Готовий до публікації');
    }

    #[Test]
    public function publication_filters_selected_products_by_brand_status_and_product_type(): void
    {
        $account = $this->createConnectorAccount();
        $configuration = $this->createProductsExportConfiguration($account->id);
        $active = $this->createProduct('CHANNEL-FILTER-A', [
            'brand' => 'Brand A',
            'merchant_type' => 'Type A',
            'is_active' => true,
        ]);
        $inactive = $this->createProduct('CHANNEL-FILTER-B', [
            'brand' => 'Brand B',
            'merchant_type' => 'Type B',
            'is_active' => false,
            'images' => ['https://example.test/product.jpg'],
        ]);

        app(ProductChannelSelectionService::class)->add(
            $this->actor,
            $this->workspace,
            $configuration->id,
            [$active->id, $inactive->id],
        );

        $membership = WorkspaceUser::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('user_id', $this->actor->id)
            ->firstOrFail();

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $active->id,
            'product_variant_id' => null,
            'external_identifier' => 'REMOTE-FILTER-A',
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => 'remote-filter-a',
            'established_by_workspace_user_id' => $membership->id,
            'established_at' => now(),
        ]);

        $component = Livewire::actingAs($this->actor)
            ->test(ManageAdobeProductsChannel::class, ['account' => $account->id])
            ->assertSee('data-testid="product-workbench-compact-header"', false)
            ->assertSee('data-testid="product-workbench-identity"', false)
            ->assertSee('data-testid="product-workbench-status-board"', false)
            ->assertSee(__('product_channels.workbench.publication.selected_tooltip', [
                'selected' => 2,
                'total' => 2,
            ]))
            ->assertSee(__('product_channels.workbench.toolbar.filters'))
            ->assertSee(__('product_channels.workbench.toolbar.columns'))
            ->assertDontSee('product-workbench-open-navigation', false)
            ->assertSee('bpOpenLightbox', false)
            ->assertSee('bp-workbench-action-column-label', false)
            ->assertDontSee('bp-workbench-action-sort-header', false);

        $table = $component->instance()->getTable();
        $this->assertTrue($table->getFiltersTriggerAction()->isModalSlideOver());
        $this->assertTrue($table->getColumnManagerTriggerAction()->isModalSlideOver());
        $this->assertNotNull($table->getColumnManagerTriggerAction()->getBadge());
        $this->assertSame('start', $table->getRecordActionsAlignment());

        $component
            ->filterTable('brand', 'Brand A')
            ->assertSee($active->name)
            ->assertDontSee($inactive->name)
            ->resetTableFilters()
            ->filterTable('is_active', '0')
            ->assertDontSee($active->name)
            ->assertSee($inactive->name)
            ->resetTableFilters()
            ->filterTable('merchant_type', 'Type B')
            ->assertDontSee($active->name)
            ->assertSee($inactive->name)
            ->resetTableFilters()
            ->filterTable('link_status', 'linked')
            ->assertSee($active->name)
            ->assertDontSee($inactive->name)
            ->resetTableFilters()
            ->filterTable('link_status', 'unlinked')
            ->assertDontSee($active->name)
            ->assertSee($inactive->name)
            ->resetTableFilters()
            ->sortTable('is_linked', 'asc')
            ->assertSee($active->name)
            ->assertSee($inactive->name)
            ->sortTable('is_active', 'desc')
            ->assertSee($active->name)
            ->assertSee($inactive->name);

        $component
            ->assertTableActionVisible('openProduct', $inactive)
            ->mountTableAction('openProduct', $inactive);

        $mountedView = $component->instance()->getMountedAction();
        $this->assertNotNull($mountedView);
        $this->assertTrue($mountedView->isModalSlideOver());
        $this->assertArrayHasKey('open_full_page_footer', $mountedView->getExtraModalFooterActions());
    }

    #[Test]
    public function publication_treats_platform_created_variant_identity_as_linked_product(): void
    {
        $account = $this->createConnectorAccount();
        $configuration = $this->createProductsExportConfiguration($account->id);
        $product = $this->createProduct('PLATFORM-LINKED-PRODUCT');
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'PLATFORM-LINKED-SKU',
            'is_active' => true,
        ]);

        app(ProductChannelSelectionService::class)->add(
            $this->actor,
            $this->workspace,
            $configuration->id,
            [$product->id],
        );

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => null,
            'product_variant_id' => $variant->id,
            'external_identifier' => $variant->sku,
            'trust_origin' => ExternalRecordLinkTrustOrigin::PlatformCreated->value,
            'external_record_discriminator' => '7001',
            'established_by_workspace_user_id' => null,
            'established_at' => now(),
        ]);

        Livewire::actingAs($this->actor)
            ->test(ManageAdobeProductsChannel::class, ['account' => $account->id])
            ->filterTable('link_status', 'linked')
            ->assertSee($product->name)
            ->resetTableFilters()
            ->filterTable('link_status', 'unlinked')
            ->assertDontSee($product->name);
    }

    #[Test]
    public function live_only_actor_can_navigate_from_channel_to_combined_execution_page(): void
    {
        $account = $this->createConnectorAccount();
        $configuration = $this->createProductsExportConfiguration($account->id);
        $selected = $this->createProduct('CHANNEL-LIVE-ONLY');

        app(ProductChannelSelectionService::class)->add(
            $this->actor,
            $this->workspace,
            $configuration->id,
            [$selected->id],
        );

        $liveOnlyActor = $this->createStaffUser(UserRole::Admin);
        $this->grantExactWorkspacePermissions($this->workspace, $liveOnlyActor, [
            WorkspacePermissions::RUN_SYNC_LIVE,
        ]);

        Livewire::actingAs($liveOnlyActor)
            ->test(ManageAdobeProductsChannel::class, ['account' => $account->id])
            ->assertOk()
            ->assertSet('canRunPreview', false)
            ->assertSet('canOpenExecution', true)
            ->assertSee('data-testid="product-channel-open-preview"', false);

        Livewire::actingAs($liveOnlyActor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertOk();
    }

    #[Test]
    public function master_products_bulk_actions_mutate_the_same_channel_membership(): void
    {
        $account = $this->createConnectorAccount();
        $configuration = $this->createProductsExportConfiguration($account->id);
        $productA = $this->createProduct('CHANNEL-BULK-A');
        $productB = $this->createProduct('CHANNEL-BULK-B');

        Livewire::actingAs($this->actor)
            ->test(ListProducts::class)
            ->mountTableBulkAction('add_to_sync_channel', [$productA, $productB])
            ->setTableBulkActionData(['sync_configuration_id' => $configuration->id])
            ->callMountedTableBulkAction()
            ->assertNotified();

        $this->assertSame(
            [$productA->id, $productB->id],
            app(SyncProductSelectionService::class)
                ->selectedProductIds($configuration->refresh()),
        );

        Livewire::actingAs($this->actor)
            ->test(ListProducts::class)
            ->mountTableBulkAction('remove_from_sync_channel', [$productB])
            ->setTableBulkActionData(['sync_configuration_id' => $configuration->id])
            ->callMountedTableBulkAction()
            ->assertNotified();

        $this->assertSame(
            [$productA->id],
            app(SyncProductSelectionService::class)
                ->selectedProductIds($configuration->refresh()),
        );
    }

    #[Test]
    public function master_products_show_channel_badges_from_the_same_membership_relation(): void
    {
        $account = $this->createConnectorAccount();
        $configuration = $this->createProductsExportConfiguration($account->id);
        $selected = $this->createProduct('CHANNEL-BADGE-SELECTED');
        $other = $this->createProduct('CHANNEL-BADGE-OTHER');

        app(ProductChannelSelectionService::class)->add(
            $this->actor,
            $this->workspace,
            $configuration->id,
            [$selected->id],
        );

        $label = app(ProductChannelSelectionService::class)
            ->labelForConfiguration($this->workspace, $configuration->id);

        Livewire::actingAs($this->actor)
            ->test(ListProducts::class)
            ->assertTableColumnStateSet('sync_channels', [$label], $selected)
            ->assertTableColumnStateSet('sync_channels', null, $other);
    }

    #[Test]
    public function direct_channel_url_fails_closed_for_ineligible_account_profile(): void
    {
        $account = $this->createConnectorAccount(overrides: [
            'auth_profile' => 'missing-profile',
        ]);

        Livewire::actingAs($this->actor)
            ->test(ManageAdobeProductsChannel::class, ['account' => $account->id])
            ->assertForbidden();
    }

    #[Test]
    public function channel_assignment_rejects_tampered_import_only_configuration(): void
    {
        $account = $this->createConnectorAccount();
        $configuration = SyncConfiguration::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products,
            'external_context' => [],
            'enabled_operations' => [SyncSemanticOperation::Import->value],
            'operational_state' => SyncConfigurationOperationalState::Enabled,
            'configuration_revision' => 'seed-import-only-revision',
        ]);
        $product = $this->createProduct('CHANNEL-TAMPER-IMPORT');

        $this->expectException(AuthorizationException::class);

        app(ProductChannelSelectionService::class)->add(
            $this->actor,
            $this->workspace,
            $configuration->id,
            [$product->id],
        );
    }

    #[Test]
    public function master_products_channel_filter_returns_only_selected_membership(): void
    {
        $account = $this->createConnectorAccount();
        $configuration = $this->createProductsExportConfiguration($account->id);
        $selected = $this->createProduct('CHANNEL-FILTER-SELECTED');
        $other = $this->createProduct('CHANNEL-FILTER-OTHER');

        app(ProductChannelSelectionService::class)->add(
            $this->actor,
            $this->workspace,
            $configuration->id,
            [$selected->id],
        );

        $component = Livewire::actingAs($this->actor)
            ->test(ListProducts::class)
            ->set('tableFilters.sync_channel.value', $configuration->id);

        $keys = array_map('intval', $component->instance()->getAllSelectableTableRecordKeys());

        $this->assertContains($selected->id, $keys);
        $this->assertNotContains($other->id, $keys);
    }

    private function createProductsExportConfiguration(string $accountId): SyncConfiguration
    {
        return SyncConfiguration::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'connector_account_id' => $accountId,
            'data_domain' => SyncDataDomain::Products,
            'external_context' => [],
            'enabled_operations' => [SyncSemanticOperation::Export->value],
            'operational_state' => SyncConfigurationOperationalState::Enabled,
            'configuration_revision' => 'seed-channel-revision',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createProduct(string $sku, array $attributes = []): Product
    {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'is_active' => true,
            ...$attributes,
        ]);
    }
}
