<?php

namespace Tests\Concerns;

use App\Enums\PriceListItemStatus;
use App\Enums\PriceListStatus;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\VariantFieldValue;
use App\Models\Workspace;
use App\Services\Sync\CreateSyncConfigurationInput;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\FieldOptionMappingMutationService;
use App\Services\Sync\SyncConfigurationService;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\SyncExternalContext;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Support\Str;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\Support\Sync\EntityTrust\EntityTrustAdobeTransportResponder;

trait InteractsWithEntityTrustFixtures
{
    protected function bindEntityTrustTransport(?EntityTrustAdobeTransportResponder $responder = null): RecordingConnectorHttpTransport
    {
        $responder ??= new EntityTrustAdobeTransportResponder;
        $transport = new RecordingConnectorHttpTransport(
            fn (ConnectorOutboundRequest $request, int $count): ConnectorHttpResult => $responder($request, $count),
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        return $transport;
    }

    protected function grantEntityTrustPermissions(Workspace $workspace, User $user): User
    {
        $this->grantExactWorkspacePermissions($workspace, $user, [
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            WorkspacePermissions::RUN_SYNC_LIVE,
        ]);

        return $user;
    }

    protected function createEntityTrustActor(Workspace $workspace): User
    {
        $actor = $this->createStaffUser(UserRole::Manager);

        return $this->grantEntityTrustPermissions($workspace, $actor);
    }

    protected function prepareEntityTrustConfiguration(ConnectorAccount $account): SyncConfiguration
    {
        $configuration = app(SyncConfigurationService::class)->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Export],
            operationalState: SyncConfigurationOperationalState::Enabled,
        ));

        $configuration = app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => 4]),
        );

        $this->publishAuthoritativeSnapshot($account, ['name', 'sku', 'status']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productBinding('name')->id,
            'name',
        );

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productVariantBinding('sku')->id,
            'sku',
        );

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productBinding('status')->id,
            'status',
        );

        return $configuration->refresh();
    }

    protected function prepareConfigurableEntityTrustConfiguration(ConnectorAccount $account): SyncConfiguration
    {
        $configuration = $this->prepareEntityTrustConfiguration($account);

        $this->publishAuthoritativeSnapshotWithOptions($account, [
            'color' => [
                ['value' => '93', 'label' => 'Blue'],
                ['value' => '94', 'label' => 'Red'],
            ],
        ]);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productVariantBinding('color')->id,
            'color',
        );

        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('field_binding_id', $this->productVariantBinding('color')->id)
            ->sole();

        app(FieldOptionMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $mapping->id,
            'blue',
            '93',
        );

        app(FieldOptionMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $mapping->id,
            'pink',
            '94',
        );

        return $configuration->refresh();
    }

    /**
     * @return array{0: Product, 1: ProductVariant}
     */
    protected function createSimpleEntityTrustProduct(Workspace $workspace, string $sku = 'ET-SIMPLE-1'): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'name' => 'Entity Trust Simple '.$sku,
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'is_active' => true,
            'base_price_cache' => 100,
        ]);

        $this->attachEntityTrustVariantPrice($workspace, $variant, 100);

        return [$product, $variant];
    }

    /**
     * @return array{0: Product, 1: list<ProductVariant>}
     */
    protected function createConfigurableEntityTrustProduct(
        Workspace $workspace,
        string $productSku = 'ET-CFG-PRODUCT',
        string $parentMagentoSku = 'MERCHANT-PARENT-SKU',
    ): array {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $productSku,
            'name' => 'Configurable Entity Trust',
            'is_active' => true,
        ]);

        $variants = [];

        foreach ([['BLUE', 'blue'], ['PINK', 'pink']] as [$suffix, $color]) {
            $variantSku = $productSku.'-'.$suffix;
            $variant = ProductVariant::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'product_id' => $product->id,
                'onec_guid' => (string) Str::uuid(),
                'sku' => $variantSku,
                'is_active' => true,
                'base_price_cache' => 100,
            ]);

            VariantFieldValue::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'variant_id' => $variant->id,
                'field_binding_id' => $this->productVariantBinding('color')->id,
                'value_text' => $color,
            ]);

            $this->attachEntityTrustVariantPrice($workspace, $variant, 100);
            $variants[] = $variant;
        }

        return [$product, $variants];
    }

    protected function attachEntityTrustVariantPrice(Workspace $workspace, ProductVariant $variant, float $price): void
    {
        $priceList = PriceList::withoutWorkspaceScope()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'is_default' => true],
            ['name' => 'Workspace Default', 'currency' => 'UAH', 'priority' => 0, 'status' => PriceListStatus::Active],
        );

        PriceListItem::withoutWorkspaceScope()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'price_list_id' => $priceList->id,
                'product_variant_id' => $variant->id,
                'quantity_min' => 1,
            ],
            ['price' => $price, 'status' => PriceListItemStatus::Active],
        );
    }
}
