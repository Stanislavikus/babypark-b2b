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
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\FieldMapping;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\Workspace;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\Receive\ReceiveProposalFlowStore;
use App\Support\Connectors\AdobePaaS\Receive\AdobeProductReceiveProposalException;
use App\Support\Connectors\AdobePaaS\Receive\AdobeProductReceiveProposalService;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use App\Support\Sync\Receive\ReceiveProposalFlowBinding;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\FieldDefinitionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\CreatesMerchantConfirmedExternalRecordLinks;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class AdobeProductReceiveProposalServiceTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use CreatesMerchantConfirmedExternalRecordLinks;
    use InteractsWithFieldMappingFixtures;
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            WorkspaceSeeder::class,
            FieldDefinitionSeeder::class,
            ConnectorFoundationSeeder::class,
        ]);

        config(['cache.default' => 'file']);
        Cache::flush();

        $this->workspace = $this->defaultWorkspace();
    }

    #[Test]
    public function trusted_product_target_equal_name_builds_equal_proposal_and_flow_consumes_once_with_actor_binding(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account)->refresh();
        [$product] = $this->createProductWithVariant($this->workspace, 'Local Product', 'SKU-EQUAL');
        $mapping = $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);
        $link = ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                'SKU-EQUAL',
                '77',
                $actor,
            ),
        );

        $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(77, 'SKU-EQUAL', 'Local Product'),
        );

        $result = app(AdobeProductReceiveProposalService::class)->build(
            actorUserId: $actor->user_id,
            workspaceId: $this->workspace->id,
            connectorAccountId: $account->id,
            targetType: FieldObjectType::Product,
            targetId: $product->id,
        );

        $this->assertSame((string) $configuration->refresh()->configuration_revision, $result->proposal->configurationRevision);
        $this->assertSame((string) $link->id, $result->proposal->trustedExternalLinkEvidenceId);
        $this->assertSame(FieldObjectType::Product, $result->proposal->targetType);
        $this->assertSame((string) $product->id, $result->proposal->targetId);
        $this->assertCount(1, $result->proposal->entries);
        $this->assertSame((string) $mapping->field_binding_id, $result->proposal->entries[0]->fieldBindingId);
        $this->assertSame(FieldObjectType::Product, $result->proposal->entries[0]->objectType);
        $this->assertSame(ReceiveDomainRoute::ProductVariantColumn, $result->proposal->entries[0]->domainRoute);
        $this->assertSame(ReceiveDiffState::Equal, $result->proposal->entries[0]->diffState);

        $binding = new ReceiveProposalFlowBinding(
            actorUserId: $actor->user_id,
            workspaceId: $this->workspace->id,
            connectorAccountId: $account->id,
            syncConfigurationId: $configuration->id,
            targetType: FieldObjectType::Product,
            targetId: $product->id,
        );

        $consumed = app(ReceiveProposalFlowStore::class)->consume($result->flowId, $binding);

        $this->assertNotNull($consumed);
        $this->assertNull(app(ReceiveProposalFlowStore::class)->consume($result->flowId, $binding));
    }

    #[Test]
    public function trusted_product_target_different_name_delegates_diff_state_and_preserves_exact_values(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product] = $this->createProductWithVariant($this->workspace, '  Local Name  ', 'SKU-DIFF');
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                'SKU-DIFF',
                '88',
                $actor,
            ),
        );

        $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(88, 'SKU-DIFF', '  Remote Name  '),
        );

        $result = app(AdobeProductReceiveProposalService::class)->build(
            actorUserId: $actor->user_id,
            workspaceId: $this->workspace->id,
            connectorAccountId: $account->id,
            targetType: FieldObjectType::Product,
            targetId: $product->id,
        );

        $entry = $result->proposal->entries[0];

        $this->assertSame(ReceiveDiffState::Differs, $entry->diffState);
        $this->assertSame('  Local Name  ', $entry->localCanonicalValue);
        $this->assertSame('  Remote Name  ', $entry->remoteCanonicalValue);
    }

    #[Test]
    public function trusted_product_variant_target_can_emit_product_owned_name_entry(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product, $variant] = $this->createProductWithVariant($this->workspace, 'Parent Name', 'SKU-VARIANT');
        $mapping = $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $this->workspace,
                $account->id,
                $variant,
                'SKU-VARIANT',
                '91',
                $actor,
            ),
        );

        $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(91, 'SKU-VARIANT', 'Remote Parent Name'),
        );

        $result = app(AdobeProductReceiveProposalService::class)->build(
            actorUserId: $actor->user_id,
            workspaceId: $this->workspace->id,
            connectorAccountId: $account->id,
            targetType: FieldObjectType::ProductVariant,
            targetId: $variant->id,
        );

        $entry = $result->proposal->entries[0];

        $this->assertSame(FieldObjectType::ProductVariant, $result->proposal->targetType);
        $this->assertSame((string) $variant->id, $result->proposal->targetId);
        $this->assertSame((string) $mapping->field_binding_id, $entry->fieldBindingId);
        $this->assertSame(FieldObjectType::Product, $entry->objectType);
        $this->assertSame(ReceiveDomainRoute::ProductVariantColumn, $entry->domainRoute);
        $this->assertSame('Parent Name', $entry->localCanonicalValue);
        $this->assertSame('Remote Parent Name', $entry->remoteCanonicalValue);
    }

    #[Test]
    public function non_default_workspace_variant_target_builds_successfully_without_ambient_workspace_context(): void
    {
        $workspaceB = Workspace::query()->create([
            'name' => 'Workspace B',
            'is_default' => false,
        ]);

        $this->assertNotSame($this->workspace->id, $workspaceB->id);

        $account = $this->createConnectorAccount($workspaceB);
        $configuration = $this->createReceiveConfiguration($account);
        [$product, $variant] = $this->createProductWithVariant($workspaceB, 'Workspace B Product', 'SKU-B');
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($workspaceB);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $workspaceB,
                $account->id,
                $variant,
                'SKU-B',
                '191',
                $actor,
            ),
        );

        $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(191, 'SKU-B', 'Workspace B Remote'),
        );

        $result = app(AdobeProductReceiveProposalService::class)->build(
            actorUserId: $actor->user_id,
            workspaceId: $workspaceB->id,
            connectorAccountId: $account->id,
            targetType: FieldObjectType::ProductVariant,
            targetId: $variant->id,
        );

        $entry = $result->proposal->entries[0];

        $this->assertSame(FieldObjectType::ProductVariant, $result->proposal->targetType);
        $this->assertSame((string) $variant->id, $result->proposal->targetId);
        $this->assertSame(FieldObjectType::Product, $entry->objectType);
        $this->assertSame(ReceiveDomainRoute::ProductVariantColumn, $entry->domainRoute);
        $this->assertSame('Workspace B Product', $entry->localCanonicalValue);
        $this->assertSame('Workspace B Remote', $entry->remoteCanonicalValue);
        $this->assertSame($workspaceB->id, $product->workspace_id);
    }

    #[Test]
    public function no_trusted_erl_fails_before_http_and_issues_no_flow(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product] = $this->createProductWithVariant($this->workspace);
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);
        $transport = $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(10, 'SKU-NEVER', 'Never'),
        );

        try {
            app(AdobeProductReceiveProposalService::class)->build(
                actorUserId: $actor->user_id,
                workspaceId: $this->workspace->id,
                connectorAccountId: $account->id,
                targetType: FieldObjectType::Product,
                targetId: $product->id,
            );
            $this->fail('Expected trusted link precondition failure.');
        } catch (AdobeProductReceiveProposalException $exception) {
            $this->assertSame('trusted_external_link_missing', $exception->reasonCode);
        }

        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function ambiguous_trusted_erl_fails_before_http_and_issues_no_flow(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product, $variant] = $this->createProductWithVariant($this->workspace);
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $this->workspace,
                $account->id,
                $variant,
                'SKU-AMB-1',
                '101',
                $actor,
            ),
        );
        ExternalRecordLink::withoutWorkspaceScope()->create(
            array_merge(
                $this->merchantConfirmedVariantLinkAttributes(
                    $this->workspace,
                    $account->id,
                    $variant,
                    'SKU-AMB-2',
                    '102',
                    $actor,
                ),
                ['external_identifier' => 'SKU-AMB-2'],
            ),
        );

        $transport = $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(101, 'SKU-AMB-1', 'Never'),
        );

        try {
            app(AdobeProductReceiveProposalService::class)->build(
                actorUserId: $actor->user_id,
                workspaceId: $this->workspace->id,
                connectorAccountId: $account->id,
                targetType: FieldObjectType::ProductVariant,
                targetId: $variant->id,
            );
            $this->fail('Expected ambiguous trusted link failure.');
        } catch (AdobeProductReceiveProposalException $exception) {
            $this->assertSame('trusted_external_link_ambiguous', $exception->reasonCode);
        }

        $this->assertSame(0, $transport->sendCount);
        $this->assertSame((string) $product->id, (string) $variant->product_id);
    }

    #[Test]
    public function foreign_workspace_or_account_link_cannot_satisfy_trust(): void
    {
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign',
            'is_default' => false,
        ]);
        $account = $this->createConnectorAccount($this->workspace);
        $foreignAccount = $this->createConnectorAccount($foreignWorkspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product] = $this->createProductWithVariant($this->workspace);
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);
        $foreignActor = $this->createWorkspaceActor($foreignWorkspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $foreignWorkspace,
                $foreignAccount->id,
                Product::withoutWorkspaceScope()->create([
                    'workspace_id' => $foreignWorkspace->id,
                    'onec_guid' => (string) Str::uuid(),
                    'sku' => 'FOREIGN-SKU',
                    'name' => 'Foreign Product',
                    'is_active' => true,
                ]),
                'FOREIGN-SKU',
                '111',
                $foreignActor,
            ),
        );

        $transport = $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(111, 'FOREIGN-SKU', 'Never'),
        );

        try {
            app(AdobeProductReceiveProposalService::class)->build(
                actorUserId: $actor->user_id,
                workspaceId: $this->workspace->id,
                connectorAccountId: $account->id,
                targetType: FieldObjectType::Product,
                targetId: $product->id,
            );
            $this->fail('Expected trusted link absence for foreign workspace/account.');
        } catch (AdobeProductReceiveProposalException $exception) {
            $this->assertSame('trusted_external_link_missing', $exception->reasonCode);
        }

        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    #[DataProvider('invalidSafeSyncContextProvider')]
    public function invalid_safe_sync_context_is_normalized_into_bounded_receive_failure(
        array $accountOverrides,
    ): void {
        $account = $this->createConnectorAccount($this->workspace, $accountOverrides);
        $configuration = $this->createReceiveConfiguration($account);
        [$product] = $this->createProductWithVariant($this->workspace, 'Local', 'SKU-CONTEXT');
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                'SKU-CONTEXT',
                '171',
                $actor,
            ),
        );

        $transport = $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(171, 'SKU-CONTEXT', 'Never'),
        );

        try {
            app(AdobeProductReceiveProposalService::class)->build(
                actorUserId: $actor->user_id,
                workspaceId: $this->workspace->id,
                connectorAccountId: $account->id,
                targetType: FieldObjectType::Product,
                targetId: $product->id,
            );
            $this->fail('Expected invalid Safe Sync context failure.');
        } catch (AdobeProductReceiveProposalException $exception) {
            $this->assertSame('safe_sync_context_invalid', $exception->reasonCode);
        }

        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function remote_identity_comes_from_discriminator_and_uses_exact_trusted_expected_sku(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product] = $this->createProductWithVariant($this->workspace, 'Local', '123');
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                '123',
                '777',
                $actor,
            ),
        );

        $capturedUri = null;
        $this->bindSafeSyncTransport(
            function (ConnectorOutboundRequest $request, int $count) use (&$capturedUri): ConnectorHttpResult {
                $capturedUri = (string) $request->request->getUri();

                return $this->verifiedProductResponse(777, '123', 'Local');
            },
        );

        app(AdobeProductReceiveProposalService::class)->build(
            actorUserId: $actor->user_id,
            workspaceId: $this->workspace->id,
            connectorAccountId: $account->id,
            targetType: FieldObjectType::Product,
            targetId: $product->id,
        );

        $this->assertSame(
            'https://shop.example.com/rest/default/V1/safe-sync/products/777?expectedSku=123',
            $capturedUri,
        );
        $this->assertStringNotContainsString('/V1/products/123', $capturedUri);
    }

    #[Test]
    public function malformed_discriminator_and_invalid_expected_sku_fail_closed_before_http(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product] = $this->createProductWithVariant($this->workspace);
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);
        $transport = $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(500, 'SKU', 'Never'),
        );

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                ' SKU-BAD ',
                '0',
                $actor,
            ),
        );

        try {
            app(AdobeProductReceiveProposalService::class)->build(
                actorUserId: $actor->user_id,
                workspaceId: $this->workspace->id,
                connectorAccountId: $account->id,
                targetType: FieldObjectType::Product,
                targetId: $product->id,
            );
            $this->fail('Expected malformed discriminator failure.');
        } catch (AdobeProductReceiveProposalException $exception) {
            $this->assertSame('trusted_logical_entity_id_invalid', $exception->reasonCode);
        }

        ExternalRecordLink::withoutWorkspaceScope()->delete();
        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                ' SKU-BAD ',
                '501',
                $actor,
            ),
        );

        try {
            app(AdobeProductReceiveProposalService::class)->build(
                actorUserId: $actor->user_id,
                workspaceId: $this->workspace->id,
                connectorAccountId: $account->id,
                targetType: FieldObjectType::Product,
                targetId: $product->id,
            );
            $this->fail('Expected invalid expected SKU failure.');
        } catch (AdobeProductReceiveProposalException $exception) {
            $this->assertSame('trusted_expected_sku_invalid', $exception->reasonCode);
        }

        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function safe_sync_identity_mismatch_sku_mismatch_and_transport_failure_issue_no_flow(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product] = $this->createProductWithVariant($this->workspace, 'Name', 'SKU-R2');
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                'SKU-R2',
                '601',
                $actor,
            ),
        );

        foreach ([
            'safe_sync_identity_mismatch' => fn (): ConnectorHttpResult => $this->verifiedProductResponse(602, 'SKU-R2', 'Mismatch'),
            'safe_sync_sku_mismatch' => fn (): ConnectorHttpResult => $this->verifiedProductResponse(601, 'SKU-OTHER', 'Mismatch'),
        ] as $expectedReason => $responder) {
            $this->bindSafeSyncTransport($responder);

            try {
                app(AdobeProductReceiveProposalService::class)->build(
                    actorUserId: $actor->user_id,
                    workspaceId: $this->workspace->id,
                    connectorAccountId: $account->id,
                    targetType: FieldObjectType::Product,
                    targetId: $product->id,
                );
                $this->fail("Expected {$expectedReason} failure.");
            } catch (AdobeProductReceiveProposalException $exception) {
                $this->assertSame($expectedReason, $exception->reasonCode);
            }
        }

        $this->app->instance(ConnectorHttpTransport::class, new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                throw new ConnectorTransportException(TransportFailureReason::Timeout);
            }
        });

        try {
            app(AdobeProductReceiveProposalService::class)->build(
                actorUserId: $actor->user_id,
                workspaceId: $this->workspace->id,
                connectorAccountId: $account->id,
                targetType: FieldObjectType::Product,
                targetId: $product->id,
            );
            $this->fail('Expected transport failure.');
        } catch (AdobeProductReceiveProposalException $exception) {
            $this->assertSame('safe_sync_transport_failure', $exception->reasonCode);
        }
    }

    #[Test]
    public function configuration_must_exist_be_enabled_and_have_import_enabled_without_side_effects(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        [$product] = $this->createProductWithVariant($this->workspace);
        $actor = $this->createWorkspaceActor($this->workspace);
        $transport = $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(701, 'SKU-CONFIG', 'Never'),
        );

        $before = SyncConfiguration::withoutWorkspaceScope()->count();

        try {
            app(AdobeProductReceiveProposalService::class)->build(
                actorUserId: $actor->user_id,
                workspaceId: $this->workspace->id,
                connectorAccountId: $account->id,
                targetType: FieldObjectType::Product,
                targetId: $product->id,
            );
            $this->fail('Expected missing configuration failure.');
        } catch (AdobeProductReceiveProposalException $exception) {
            $this->assertSame('receive_configuration_not_found', $exception->reasonCode);
        }

        $this->assertSame($before, SyncConfiguration::withoutWorkspaceScope()->count());
        $this->assertSame(0, $transport->sendCount);

        $configuration = $this->createReceiveConfiguration($account);
        $this->createCanonicalNameMapping($account, $configuration);
        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                $product->sku,
                '701',
                $actor,
            ),
        );

        $configuration->forceFill(['operational_state' => SyncConfigurationOperationalState::Paused])->save();

        try {
            app(AdobeProductReceiveProposalService::class)->build(
                actorUserId: $actor->user_id,
                workspaceId: $this->workspace->id,
                connectorAccountId: $account->id,
                targetType: FieldObjectType::Product,
                targetId: $product->id,
            );
            $this->fail('Expected paused configuration failure.');
        } catch (AdobeProductReceiveProposalException $exception) {
            $this->assertSame('receive_configuration_not_enabled', $exception->reasonCode);
        }

        $configuration->forceFill([
            'operational_state' => SyncConfigurationOperationalState::Enabled,
            'enabled_operations' => ['export'],
        ])->save();

        try {
            app(AdobeProductReceiveProposalService::class)->build(
                actorUserId: $actor->user_id,
                workspaceId: $this->workspace->id,
                connectorAccountId: $account->id,
                targetType: FieldObjectType::Product,
                targetId: $product->id,
            );
            $this->fail('Expected import-disabled configuration failure.');
        } catch (AdobeProductReceiveProposalException $exception) {
            $this->assertSame('receive_import_not_enabled', $exception->reasonCode);
        }
    }

    #[Test]
    public function configuration_revision_change_during_http_invalidates_build(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product] = $this->createProductWithVariant($this->workspace, 'Local', 'SKU-REV');
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                'SKU-REV',
                '801',
                $actor,
            ),
        );

        $this->bindSafeSyncTransport(function (ConnectorOutboundRequest $request, int $count) use ($configuration): ConnectorHttpResult {
            SyncConfiguration::withoutWorkspaceScope()
                ->whereKey($configuration->id)
                ->update(['configuration_revision' => str_repeat('f', 64)]);

            return $this->verifiedProductResponse(801, 'SKU-REV', 'Remote');
        });

        try {
            app(AdobeProductReceiveProposalService::class)->build(
                actorUserId: $actor->user_id,
                workspaceId: $this->workspace->id,
                connectorAccountId: $account->id,
                targetType: FieldObjectType::Product,
                targetId: $product->id,
            );
            $this->fail('Expected configuration changed failure.');
        } catch (AdobeProductReceiveProposalException $exception) {
            $this->assertSame('receive_configuration_changed', $exception->reasonCode);
        }
    }

    #[Test]
    public function target_workspace_and_relation_validation_fail_before_or_after_http_as_required(): void
    {
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Other',
            'is_default' => false,
        ]);

        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product, $variant] = $this->createProductWithVariant($this->workspace);
        $foreignProduct = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-FOR-PROD',
            'name' => 'Foreign Product',
            'is_active' => true,
        ]);
        $foreignVariant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'product_id' => $foreignProduct->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-FOR-VAR',
            'is_active' => true,
        ]);
        $brokenVariant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $foreignProduct->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-BROKEN-VAR',
            'is_active' => true,
        ]);
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);
        $transport = $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(1, 'x', 'Never'),
        );

        foreach ([
            [FieldObjectType::Product, $foreignProduct->id, 'receive_target_workspace_mismatch'],
            [FieldObjectType::ProductVariant, $foreignVariant->id, 'receive_target_workspace_mismatch'],
            [FieldObjectType::ProductVariant, $brokenVariant->id, 'receive_variant_product_relation_invalid'],
        ] as [$targetType, $targetId, $reasonCode]) {
            try {
                app(AdobeProductReceiveProposalService::class)->build(
                    actorUserId: $actor->user_id,
                    workspaceId: $this->workspace->id,
                    connectorAccountId: $account->id,
                    targetType: $targetType,
                    targetId: $targetId,
                );
                $this->fail("Expected {$reasonCode}.");
            } catch (AdobeProductReceiveProposalException $exception) {
                $this->assertSame($reasonCode, $exception->reasonCode);
            }
        }

        $this->assertSame(0, $transport->sendCount);
        $this->assertSame((string) $product->id, (string) $variant->product_id);
    }

    #[Test]
    public function local_product_name_is_read_after_http_call(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product] = $this->createProductWithVariant($this->workspace, 'Before HTTP', 'SKU-FRESH');
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                'SKU-FRESH',
                '901',
                $actor,
            ),
        );

        $this->bindSafeSyncTransport(function (ConnectorOutboundRequest $request, int $count) use ($product): ConnectorHttpResult {
            Product::withoutWorkspaceScope()->whereKey($product->id)->update(['name' => 'After HTTP']);

            return $this->verifiedProductResponse(901, 'SKU-FRESH', 'Remote Fresh');
        });

        $result = app(AdobeProductReceiveProposalService::class)->build(
            actorUserId: $actor->user_id,
            workspaceId: $this->workspace->id,
            connectorAccountId: $account->id,
            targetType: FieldObjectType::Product,
            targetId: $product->id,
        );

        $this->assertSame('After HTTP', $result->proposal->entries[0]->localCanonicalValue);
        $this->assertSame('Remote Fresh', $result->proposal->entries[0]->remoteCanonicalValue);
    }

    #[Test]
    public function no_name_mapping_returns_bounded_no_executable_outcome_and_no_flow(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $this->createReceiveConfiguration($account);
        [$product] = $this->createProductWithVariant($this->workspace, 'Local', 'SKU-NOMAP');
        $actor = $this->createWorkspaceActor($this->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                'SKU-NOMAP',
                '1001',
                $actor,
            ),
        );

        $transport = $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(1001, 'SKU-NOMAP', 'Remote'),
        );

        try {
            app(AdobeProductReceiveProposalService::class)->build(
                actorUserId: $actor->user_id,
                workspaceId: $this->workspace->id,
                connectorAccountId: $account->id,
                targetType: FieldObjectType::Product,
                targetId: $product->id,
            );
            $this->fail('Expected no executable name mapping failure.');
        } catch (AdobeProductReceiveProposalException $exception) {
            $this->assertSame('receive_no_executable_name_mapping', $exception->reasonCode);
        }

        $this->assertSame(1, $transport->sendCount);
    }

    #[Test]
    #[DataProvider('invalidRemoteNameProvider')]
    public function invalid_gap029_remote_name_value_yields_unsupported_candidate_without_mutation(string $remoteName): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product] = $this->createProductWithVariant($this->workspace, 'Local Name', 'SKU-NAME-BLOCKED');
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                'SKU-NAME-BLOCKED',
                '1051',
                $actor,
            ),
        );

        $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(1051, 'SKU-NAME-BLOCKED', $remoteName),
        );

        $result = app(AdobeProductReceiveProposalService::class)->build(
            actorUserId: $actor->user_id,
            workspaceId: $this->workspace->id,
            connectorAccountId: $account->id,
            targetType: FieldObjectType::Product,
            targetId: $product->id,
        );

        $entry = $result->proposal->entries[0];

        $this->assertSame(ReceiveDiffState::UnsupportedOrBlocked, $entry->diffState);
        $this->assertSame(ReceiveDomainRoute::Unsupported, $entry->domainRoute);
        $this->assertSame('name_value_not_executable', $entry->blockedReasonCode);
        $this->assertSame($remoteName, $entry->remoteCanonicalValue);
        $this->assertSame('Local Name', Product::withoutWorkspaceScope()->findOrFail($product->id)->name);
    }

    #[Test]
    #[DataProvider('validRemoteNameProvider')]
    public function valid_gap029_remote_name_boundary_values_remain_executable_and_preserved(string $remoteName): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product] = $this->createProductWithVariant($this->workspace, 'Local Name', 'SKU-NAME-VALID');
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                'SKU-NAME-VALID',
                '1061',
                $actor,
            ),
        );

        $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(1061, 'SKU-NAME-VALID', $remoteName),
        );

        $result = app(AdobeProductReceiveProposalService::class)->build(
            actorUserId: $actor->user_id,
            workspaceId: $this->workspace->id,
            connectorAccountId: $account->id,
            targetType: FieldObjectType::Product,
            targetId: $product->id,
        );

        $entry = $result->proposal->entries[0];

        $this->assertSame(ReceiveDomainRoute::ProductVariantColumn, $entry->domainRoute);
        $this->assertSame(ReceiveDiffState::Differs, $entry->diffState);
        $this->assertSame($remoteName, $entry->remoteCanonicalValue);
    }

    #[Test]
    #[DataProvider('blockedNameMappingProvider')]
    public function mapped_but_noncanonical_or_inactive_name_metadata_yields_unsupported_candidate(
        callable $forgeMapping,
        string $expectedBlockedReasonCode,
    ): void {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product] = $this->createProductWithVariant($this->workspace, 'Local', 'SKU-BLOCKED');
        $actor = $this->createWorkspaceActor($this->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                'SKU-BLOCKED',
                '1101',
                $actor,
            ),
        );

        $forgeMapping($this, $configuration);

        $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(1101, 'SKU-BLOCKED', 'Remote'),
        );

        $result = app(AdobeProductReceiveProposalService::class)->build(
            actorUserId: $actor->user_id,
            workspaceId: $this->workspace->id,
            connectorAccountId: $account->id,
            targetType: FieldObjectType::Product,
            targetId: $product->id,
        );

        $entry = $result->proposal->entries[0];

        $this->assertSame(ReceiveDiffState::UnsupportedOrBlocked, $entry->diffState);
        $this->assertSame(ReceiveDomainRoute::Unsupported, $entry->domainRoute);
        $this->assertSame($expectedBlockedReasonCode, $entry->blockedReasonCode);
    }

    #[Test]
    public function receive_http_runs_at_transaction_level_zero_and_service_performs_no_local_mutations(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account)->refresh();
        [$product] = $this->createProductWithVariant($this->workspace, 'Stable', 'SKU-TX');
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);
        $baselineTransactionLevel = DB::transactionLevel();

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                'SKU-TX',
                '1201',
                $actor,
            ),
        );

        $mutatingSql = [];
        DB::listen(function (QueryExecuted $query) use (&$mutatingSql): void {
            if (preg_match('/^\s*(insert|update|delete)\s/i', $query->sql) === 1) {
                $mutatingSql[] = $query->sql;
            }
        });

        $this->bindSafeSyncTransport(function (ConnectorOutboundRequest $request, int $count) use ($baselineTransactionLevel): ConnectorHttpResult {
            $this->assertSame($baselineTransactionLevel, DB::transactionLevel());

            return $this->verifiedProductResponse(1201, 'SKU-TX', 'Stable');
        });

        $beforeProduct = Product::withoutWorkspaceScope()->findOrFail($product->id)->name;
        $beforeSyncRuns = SyncRun::withoutWorkspaceScope()->count();
        $beforeSyncRunItems = SyncRunItem::withoutWorkspaceScope()->count();

        $result = app(AdobeProductReceiveProposalService::class)->build(
            actorUserId: $actor->user_id,
            workspaceId: $this->workspace->id,
            connectorAccountId: $account->id,
            targetType: FieldObjectType::Product,
            targetId: $product->id,
        );

        $this->assertSame('Stable', $beforeProduct);
        $this->assertSame('Stable', Product::withoutWorkspaceScope()->findOrFail($product->id)->name);
        $this->assertSame($beforeSyncRuns, SyncRun::withoutWorkspaceScope()->count());
        $this->assertSame($beforeSyncRunItems, SyncRunItem::withoutWorkspaceScope()->count());
        $this->assertSame([], $mutatingSql);
        $this->assertSame(ReceiveDiffState::Equal, $result->proposal->entries[0]->diffState);
    }

    #[Test]
    public function flow_binding_from_service_fails_closed_for_wrong_actor(): void
    {
        $account = $this->createConnectorAccount($this->workspace);
        $configuration = $this->createReceiveConfiguration($account);
        [$product] = $this->createProductWithVariant($this->workspace, 'Name', 'SKU-FLOW');
        $this->createCanonicalNameMapping($account, $configuration);
        $actor = $this->createWorkspaceActor($this->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $this->workspace,
                $account->id,
                $product,
                'SKU-FLOW',
                '1301',
                $actor,
            ),
        );

        $this->bindSafeSyncTransport(
            fn (): ConnectorHttpResult => $this->verifiedProductResponse(1301, 'SKU-FLOW', 'Name'),
        );

        $result = app(AdobeProductReceiveProposalService::class)->build(
            actorUserId: $actor->user_id,
            workspaceId: $this->workspace->id,
            connectorAccountId: $account->id,
            targetType: FieldObjectType::Product,
            targetId: $product->id,
        );

        $wrongBinding = new ReceiveProposalFlowBinding(
            actorUserId: '999999',
            workspaceId: $this->workspace->id,
            connectorAccountId: $account->id,
            syncConfigurationId: $configuration->id,
            targetType: FieldObjectType::Product,
            targetId: $product->id,
        );

        $this->assertNull(app(ReceiveProposalFlowStore::class)->consume($result->flowId, $wrongBinding));
    }

    public static function blockedNameMappingProvider(): array
    {
        return [
            'inactive binding' => [
                fn (self $test, SyncConfiguration $configuration) => $test->createForgedNameMapping(
                    $configuration,
                    bindingOverrides: ['status' => AttributeStatus::Archived],
                ),
                'binding_inactive',
            ],
            'inactive definition' => [
                fn (self $test, SyncConfiguration $configuration) => $test->createForgedNameMapping(
                    $configuration,
                    definitionOverrides: ['status' => AttributeStatus::Archived],
                ),
                'definition_inactive',
            ],
            'wrong datatype' => [
                fn (self $test, SyncConfiguration $configuration) => $test->createForgedNameMapping(
                    $configuration,
                    definitionOverrides: ['data_type' => AttributeDataType::LongText],
                ),
                'name_mapping_not_canonical',
            ],
            'wrong code with products name path' => [
                fn (self $test, SyncConfiguration $configuration) => $test->createForgedNameMapping(
                    $configuration,
                    definitionOverrides: ['code' => 'brand'],
                ),
                'name_mapping_not_canonical',
            ],
            'workspace custom binding to products name' => [
                fn (self $test, SyncConfiguration $configuration) => $test->createForgedNameMapping(
                    $configuration,
                    definitionOverrides: [
                        'workspace_id' => $configuration->workspace_id,
                        'scope' => AttributeScope::WorkspaceCustom,
                    ],
                    bindingOverrides: ['workspace_id' => $configuration->workspace_id],
                ),
                'name_mapping_not_canonical',
            ],
            'wrong storage type' => [
                fn (self $test, SyncConfiguration $configuration) => $test->createForgedNameMapping(
                    $configuration,
                    bindingOverrides: ['storage_type' => AttributeStorageType::Dynamic],
                ),
                'name_mapping_not_canonical',
            ],
            'wrong storage path' => [
                fn (self $test, SyncConfiguration $configuration) => $test->createForgedNameMapping(
                    $configuration,
                    bindingOverrides: ['storage_path' => 'product_variants.sku'],
                ),
                'name_mapping_not_canonical',
            ],
        ];
    }

    public static function invalidSafeSyncContextProvider(): array
    {
        return [
            'disabled connector account' => [
                ['is_enabled' => false],
            ],
            'incomplete adobe settings' => [
                ['base_url' => null],
            ],
            'unsupported auth profile' => [
                ['auth_profile' => 'test_sync_support'],
            ],
        ];
    }

    public static function invalidRemoteNameProvider(): array
    {
        return [
            'whitespace-only remote name' => ['   '],
            '256 character remote name' => [str_repeat('N', 256)],
        ];
    }

    public static function validRemoteNameProvider(): array
    {
        return [
            '255 character remote name' => [str_repeat('N', 255)],
            'preserved padded remote name' => ['  Valid Name  '],
        ];
    }

    private function bindSafeSyncTransport(callable $responder): RecordingConnectorHttpTransport
    {
        $callable = \Closure::fromCallable($responder);
        $parameterCount = (new \ReflectionFunction($callable))->getNumberOfParameters();
        $transport = new RecordingConnectorHttpTransport(
            function (ConnectorOutboundRequest $request, int $count) use ($callable, $parameterCount): ConnectorHttpResult {
                return match ($parameterCount) {
                    0 => $callable(),
                    1 => $callable($request),
                    default => $callable($request, $count),
                };
            },
        );

        $this->app->instance(ConnectorHttpTransport::class, $transport);

        return $transport;
    }

    private function verifiedProductResponse(int $logicalEntityId, string $sku, string $name): ConnectorHttpResult
    {
        return new ConnectorHttpResult(200, [], json_encode([
            'logical_entity_id' => $logicalEntityId,
            'sku' => $sku,
            'type_id' => 'simple',
            'name' => $name,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{0: Product, 1: ProductVariant}
     */
    private function createProductWithVariant(
        Workspace $workspace,
        string $productName = 'Local Product',
        ?string $sku = null,
    ): array {
        $sku ??= 'SKU-'.Str::upper(Str::random(8));

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku.'-P',
            'name' => $productName,
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'is_active' => true,
        ]);

        return [$product, $variant];
    }

    private function createCanonicalNameMapping(ConnectorAccount $account, SyncConfiguration $configuration): FieldMapping
    {
        $this->publishAuthoritativeSnapshot($account, ['name']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productBinding('name')->id,
            'name',
        );

        return FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('external_field_key', 'name')
            ->sole();
    }

    private function createReceiveConfiguration(ConnectorAccount $account, array $overrides = []): SyncConfiguration
    {
        return SyncConfiguration::withoutWorkspaceScope()->create(array_merge([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products,
            'external_context' => [],
            'enabled_operations' => ['import'],
            'operational_state' => SyncConfigurationOperationalState::Enabled,
            'configuration_revision' => hash('sha256', 'receive-config:'.$account->id.':'.Str::uuid()->toString()),
        ], $overrides));
    }

    private function createForgedNameMapping(
        SyncConfiguration $configuration,
        array $definitionOverrides = [],
        array $bindingOverrides = [],
    ): FieldMapping {
        $definition = FieldDefinition::withoutWorkspaceScope()->create(array_merge([
            'workspace_id' => $configuration->workspace_id,
            'code' => 'name',
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Назва'],
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ], $definitionOverrides));

        $binding = FieldBinding::withoutWorkspaceScope()->create(array_merge([
            'workspace_id' => $configuration->workspace_id,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Column,
            'storage_path' => 'products.name',
            'field_group' => 'basic_information',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 999,
            'status' => AttributeStatus::Active,
        ], $bindingOverrides));

        return FieldMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $configuration->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'field_binding_id' => $binding->id,
            'external_field_key' => 'name',
        ]);
    }
}
