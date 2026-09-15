<?php

namespace Tests\Feature\Sync;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Enums\ReceiveDiffState;
use App\Enums\ReceiveDomainRoute;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncLiveOutcome;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Models\ExternalRecordLink;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\FieldMapping;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncConfiguration;
use App\Models\SyncRunItem;
use App\Models\VariantFieldValue;
use App\Services\Fields\GovernedDynamicFieldValueWriter;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\FieldOptionMappingMutationService;
use App\Support\Connectors\AdobePaaS\Receive\AdobeProductReceiveApplyService;
use App\Support\Connectors\AdobePaaS\Receive\AdobeProductReceiveProposalService;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\FieldDefinitionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\CreatesMerchantConfirmedExternalRecordLinks;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class AdobeProductDynamicSelectReceiveTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use CreatesMerchantConfirmedExternalRecordLinks;
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            WorkspaceSeeder::class,
            FieldDefinitionSeeder::class,
            ConnectorFoundationSeeder::class,
            WorkspaceRbacPermissionSeeder::class,
        ]);
        $this->configureAdobePaaSSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Live],
        ]);
    }

    #[Test]
    public function local_absent_dynamic_select_is_imported_from_magento_option_mapping(): void
    {
        $fixture = $this->fixture('902', '902');
        $dynamicEntry = collect($fixture['proposal']->proposal->entries)->first(
            fn ($entry) => $entry->domainRoute === ReceiveDomainRoute::DynamicField,
        );

        $this->assertNotNull($dynamicEntry);
        $this->assertSame(ReceiveDiffState::LocalAbsent, $dynamicEntry->diffState);
        $this->assertSame('internal_red', $dynamicEntry->remoteCanonicalValue);

        $run = app(AdobeProductReceiveApplyService::class)->apply(
            $fixture['actor'],
            $fixture['proposal']->flowId,
            $fixture['workspace']->id,
            $fixture['account']->id,
            $fixture['configuration']->id,
            FieldObjectType::ProductVariant,
            $fixture['variant']->id,
        );

        $this->assertSame('internal_red', VariantFieldValue::withoutWorkspaceScope()
            ->where('variant_id', $fixture['variant']->id)
            ->where('field_binding_id', $fixture['binding']->id)
            ->sole()->value_text);
        $this->assertSame('completed', $run->status->value);
        $item = SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->sole();
        $this->assertSame(SyncLiveOutcome::Synchronized, $item->liveOutcome());
        $this->assertTrue(collect($item->findings)->contains(
            fn (array $finding): bool => ($finding['code'] ?? null) === 'receive_dynamic_field_applied'
                && ($finding['field_binding_id'] ?? null) === $fixture['binding']->id,
        ));
        $this->assertSame(2, $fixture['transport']->sendCount);
    }

    #[Test]
    public function stale_local_dynamic_select_after_proposal_is_not_overwritten(): void
    {
        $fixture = $this->fixture('902', '902');
        app(GovernedDynamicFieldValueWriter::class)->set(
            $fixture['workspace']->id,
            FieldObjectType::ProductVariant,
            $fixture['variant']->id,
            $fixture['binding']->id,
            'internal_blue',
        );

        $run = app(AdobeProductReceiveApplyService::class)->apply(
            $fixture['actor'],
            $fixture['proposal']->flowId,
            $fixture['workspace']->id,
            $fixture['account']->id,
            $fixture['configuration']->id,
            FieldObjectType::ProductVariant,
            $fixture['variant']->id,
        );

        $this->assertSame('internal_blue', VariantFieldValue::withoutWorkspaceScope()
            ->where('variant_id', $fixture['variant']->id)
            ->where('field_binding_id', $fixture['binding']->id)
            ->sole()->value_text);
        $item = SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->sole();
        $this->assertSame(SyncLiveOutcome::NotApplied, $item->liveOutcome());
        $this->assertSame('receive_apply_local_value_changed', $item->findings[0]['code']);
    }

    #[Test]
    public function remote_absent_dynamic_select_is_not_interpreted_as_clear(): void
    {
        $fixture = $this->fixture(null, null, initialLocal: 'internal_red');

        $this->assertFalse(collect($fixture['proposal']->proposal->entries)->contains(
            fn ($entry) => $entry->domainRoute === ReceiveDomainRoute::DynamicField,
        ));
        $this->assertSame('internal_red', VariantFieldValue::withoutWorkspaceScope()
            ->where('variant_id', $fixture['variant']->id)
            ->where('field_binding_id', $fixture['binding']->id)
            ->sole()->value_text);
    }

    #[Test]
    public function ambiguous_external_option_mapping_is_blocked_in_proposal(): void
    {
        $fixture = $this->fixture('902', '902', ambiguousOptions: true);
        $entry = collect($fixture['proposal']->proposal->entries)->first(
            fn ($candidate) => $candidate->fieldBindingId === $fixture['binding']->id,
        );

        $this->assertNotNull($entry);
        $this->assertSame(ReceiveDiffState::UnsupportedOrBlocked, $entry->diffState);
        $this->assertSame(ReceiveDomainRoute::Unsupported, $entry->domainRoute);
        $this->assertSame('dynamic_select_option_mapping_ambiguous', $entry->blockedReasonCode);
    }

    #[Test]
    public function changed_remote_dynamic_select_after_proposal_is_not_applied(): void
    {
        $fixture = $this->fixture('902', '903');

        $run = app(AdobeProductReceiveApplyService::class)->apply(
            $fixture['actor'],
            $fixture['proposal']->flowId,
            $fixture['workspace']->id,
            $fixture['account']->id,
            $fixture['configuration']->id,
            FieldObjectType::ProductVariant,
            $fixture['variant']->id,
        );

        $this->assertDatabaseMissing('variant_field_values', [
            'variant_id' => $fixture['variant']->id,
            'field_binding_id' => $fixture['binding']->id,
        ]);
        $item = SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->sole();
        $this->assertSame(SyncLiveOutcome::NotApplied, $item->liveOutcome());
        $this->assertSame('receive_apply_remote_value_changed', $item->findings[0]['code']);
    }

    /** @return array<string, mixed> */
    private function fixture(
        ?string $proposalOption,
        ?string $applyOption,
        ?string $initialLocal = null,
        bool $ambiguousOptions = false,
    ): array {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $configuration = SyncConfiguration::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products,
            'external_context' => [],
            'enabled_operations' => [SyncSemanticOperation::Import->value],
            'operational_state' => SyncConfigurationOperationalState::Enabled,
            'configuration_revision' => hash('sha256', 'dynamic-receive-'.Str::uuid()),
        ]);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'DYN-RECEIVE-P',
            'name' => 'Dynamic Receive',
            'is_active' => true,
        ]);
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'DYN-RECEIVE-V',
            'is_active' => true,
        ]);
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'merchant_color',
            'data_type' => AttributeDataType::Select,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Merchant Color'],
            'description' => null,
            'validation_rules' => ['options' => [
                ['code' => 'internal_red', 'labels' => ['uk' => 'Red']],
                ['code' => 'internal_blue', 'labels' => ['uk' => 'Blue']],
            ]],
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);
        $binding = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::ProductVariant,
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

        $this->publishAuthoritativeSnapshot($account, ['name', 'merchant_color']);
        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productBinding('name')->id,
            'name',
        );
        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'merchant_color',
        );
        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('field_binding_id', $binding->id)
            ->sole();
        app(FieldOptionMappingMutationService::class)->replaceAuthoritativeSet(
            $account,
            $configuration->id,
            $mapping->id,
            [
                ['internal_option_key' => 'internal_red', 'external_option_value' => '902'],
                ['internal_option_key' => 'internal_blue', 'external_option_value' => $ambiguousOptions ? '902' : '903'],
            ],
        );
        $configuration = $configuration->fresh();

        if ($initialLocal !== null) {
            app(GovernedDynamicFieldValueWriter::class)->set(
                $workspace->id,
                FieldObjectType::ProductVariant,
                $variant->id,
                $binding->id,
                $initialLocal,
            );
        }

        $actor = $this->createStaffUser(UserRole::Manager);
        $membership = $this->grantExactWorkspacePermissions(
            $workspace,
            $actor,
            [WorkspacePermissions::RUN_SYNC_LIVE],
        );
        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $workspace,
                $account->id,
                $variant,
                'DYN-RECEIVE-V',
                '77',
                $membership,
            ),
        );

        $transport = new RecordingConnectorHttpTransport(
            function (ConnectorOutboundRequest $request, int $count) use ($proposalOption, $applyOption): ConnectorHttpResult {
                $option = $count === 1 ? $proposalOption : $applyOption;
                $payload = [
                    'id' => 77,
                    'sku' => 'DYN-RECEIVE-V',
                    'type_id' => 'simple',
                    'name' => 'Dynamic Receive',
                    'custom_attributes' => [],
                ];

                if ($option !== null) {
                    $payload['custom_attributes'][] = [
                        'attribute_code' => 'merchant_color',
                        'value' => $option,
                    ];
                }

                return new ConnectorHttpResult(200, [], json_encode($payload, JSON_THROW_ON_ERROR));
            },
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $proposal = app(AdobeProductReceiveProposalService::class)->build(
            actorUserId: $actor->id,
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            targetType: FieldObjectType::ProductVariant,
            targetId: $variant->id,
        );

        return compact(
            'workspace', 'account', 'configuration', 'product', 'variant', 'binding',
            'actor', 'transport', 'proposal',
        );
    }
}
