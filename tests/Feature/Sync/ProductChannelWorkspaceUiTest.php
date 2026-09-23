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
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Sync\ProductChannelSelectionService;
use App\Services\Sync\SyncDataSetupLandingService;
use App\Services\Sync\SyncProductSelectionService;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('data-testid="product-workbench-open-navigation"', false)
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

        $component->assertTableActionVisible('openProduct', $inactive);
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
