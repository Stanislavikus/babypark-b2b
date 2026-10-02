<?php

namespace Tests\Feature\Sync;

use App\Enums\AdobeProductCategoryAssignmentState;
use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Enums\SyncLiveOutcome;
use App\Enums\UserRole;
use App\Models\AdobeProductCategoryAssignment;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionMetadata;
use App\Support\Connectors\AdobePaaS\AdobeProductExportLiveRunContext;
use App\Support\Connectors\AdobePaaS\Category\AdobeProductCategoryRelationExecutor;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticOperation;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use App\Support\Sync\Live\SyncLiveConsequentialWriteGate;
use App\Support\Sync\Live\SyncLiveProductExecutionResult;
use App\Support\Sync\Preview\ProductExecutionAggregate;
use App\Support\Sync\Preview\ProductExecutionImageInput;
use App\Support\Sync\Preview\ProductExecutionImageStructuralState;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

final class MagentoCategoryRelationRuntimeTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function provider_only_desired_relation_is_preserved_without_ownership_adoption(): void
    {
        [$account, $product, $variant, $link] = $this->trustedSimple('501');
        $remoteCategories = ['7'];
        $transport = $this->bindCategoryTransport($variant->sku, 501, $remoteCategories);

        $result = $this->executeSimple(
            $account->workspace_id,
            $account->id,
            $product->id,
            $variant->id,
            categoryId: 10,
            externalCategoryId: '7',
        );

        $this->assertSame(SyncLiveOutcome::Synchronized, $result->outcome);
        $this->assertSame([], $this->writeMethods($transport));
        $this->assertSame(0, AdobeProductCategoryAssignment::withoutWorkspaceScope()->count());
        $this->assertSame(['7'], $remoteCategories);
    }

    #[Test]
    public function absent_desired_relation_uses_one_granular_post_and_persists_managed_anchor(): void
    {
        [$account, $product, $variant, $link] = $this->trustedSimple('501');
        $remoteCategories = ['6'];
        $transport = $this->bindCategoryTransport($variant->sku, 501, $remoteCategories);

        $result = $this->executeSimple(
            $account->workspace_id,
            $account->id,
            $product->id,
            $variant->id,
            categoryId: 10,
            externalCategoryId: '7',
        );

        $this->assertSame(SyncLiveOutcome::Synchronized, $result->outcome);
        $this->assertSame(['POST'], $this->writeMethods($transport));
        $this->assertSame(['6', '7'], $remoteCategories);

        $assignment = AdobeProductCategoryAssignment::withoutWorkspaceScope()->sole();
        $this->assertSame($link->id, $assignment->external_record_link_id);
        $this->assertSame('7', $assignment->external_category_id);
        $this->assertSame(AdobeProductCategoryAssignmentState::Managed, $assignment->state);
        $this->assertSame('501', $assignment->anchor_entity_id);
        $this->assertNull($assignment->attempt_dispatched_at);

        $post = collect($transport->recordedRequests)
            ->first(fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'POST');
        $this->assertNotNull($post);
        $this->assertStringContainsString('/rest/all/V1/categories/7/products', $post->request->getUri()->getPath());
        $this->assertSame([
            'productLink' => [
                'sku' => $variant->sku,
                'category_id' => '7',
            ],
        ], json_decode((string) $post->request->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function ambiguous_add_never_promotes_to_managed_from_later_presence(): void
    {
        [$account, $product, $variant] = $this->trustedSimple('501');
        $remoteCategories = ['6'];
        $transport = $this->bindCategoryTransport(
            $variant->sku,
            501,
            $remoteCategories,
            ambiguousPost: true,
        );

        $first = $this->executeSimple(
            $account->workspace_id,
            $account->id,
            $product->id,
            $variant->id,
            categoryId: 10,
            externalCategoryId: '7',
        );

        $this->assertSame(SyncLiveOutcome::Ambiguous, $first->outcome);
        $assignment = AdobeProductCategoryAssignment::withoutWorkspaceScope()->sole();
        $this->assertSame(AdobeProductCategoryAssignmentState::AddAmbiguous, $assignment->state);
        $this->assertNull($assignment->anchor_entity_id);
        $this->assertContains('7', $remoteCategories);

        $writesBefore = count($this->writeMethods($transport));

        $second = $this->executeSimple(
            $account->workspace_id,
            $account->id,
            $product->id,
            $variant->id,
            categoryId: 10,
            externalCategoryId: '7',
        );

        $this->assertSame(SyncLiveOutcome::Synchronized, $second->outcome);
        $this->assertSame($writesBefore, count($this->writeMethods($transport)));
        $assignment->refresh();
        $this->assertSame(AdobeProductCategoryAssignmentState::AddAmbiguous, $assignment->state);
        $this->assertNull($assignment->anchor_entity_id);
    }

    #[Test]
    public function category_change_adds_new_managed_relation_before_deleting_old_one(): void
    {
        [$account, $product, $variant, $link] = $this->trustedSimple('501');
        $remoteCategories = ['6'];
        $this->managedAssignment($account->workspace_id, $account->id, $link->id, '6', '501');
        $transport = $this->bindCategoryTransport($variant->sku, 501, $remoteCategories);

        $result = $this->executeSimple(
            $account->workspace_id,
            $account->id,
            $product->id,
            $variant->id,
            categoryId: 10,
            externalCategoryId: '7',
        );

        $this->assertSame(SyncLiveOutcome::Synchronized, $result->outcome);
        $this->assertSame(['POST', 'DELETE'], $this->writeMethods($transport));
        $delete = collect($transport->recordedRequests)
            ->first(fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'DELETE');
        $this->assertNotNull($delete);
        $this->assertStringContainsString(
            '/rest/all/V1/categories/6/products/'.rawurlencode($variant->sku),
            $delete->request->getUri()->getPath(),
        );
        $this->assertSame(['7'], $remoteCategories);

        $rows = AdobeProductCategoryAssignment::withoutWorkspaceScope()
            ->orderBy('external_category_id')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('7', $rows->first()->external_category_id);
        $this->assertSame(AdobeProductCategoryAssignmentState::Managed, $rows->first()->state);
    }

    #[Test]
    public function anchor_entity_drift_drops_delete_rights_without_remote_mutation(): void
    {
        [$account, $product, $variant, $link] = $this->trustedSimple('501');
        $remoteCategories = ['6'];
        $this->managedAssignment($account->workspace_id, $account->id, $link->id, '6', '999');
        $transport = $this->bindCategoryTransport($variant->sku, 501, $remoteCategories);

        $result = $this->executeSimple(
            $account->workspace_id,
            $account->id,
            $product->id,
            $variant->id,
            categoryId: 10,
            externalCategoryId: '6',
        );

        $this->assertSame(SyncLiveOutcome::Ambiguous, $result->outcome);
        $this->assertSame([], $this->writeMethods($transport));
        $this->assertSame(['6'], $remoteCategories);

        $assignment = AdobeProductCategoryAssignment::withoutWorkspaceScope()->sole();
        $this->assertSame(AdobeProductCategoryAssignmentState::Managed, $assignment->state);
        $this->assertSame('999', $assignment->anchor_entity_id);
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->code === 'category_relation_anchor_entity_drift',
        ));
    }

    #[Test]
    public function invalid_external_category_id_fails_before_any_remote_write(): void
    {
        [$account, $product, $variant] = $this->trustedSimple('501');
        $remoteCategories = ['6'];
        $transport = $this->bindCategoryTransport($variant->sku, 501, $remoteCategories);

        $result = $this->executeSimple(
            $account->workspace_id,
            $account->id,
            $product->id,
            $variant->id,
            categoryId: 10,
            externalCategoryId: 'not-a-magento-id',
        );

        $this->assertSame(SyncLiveOutcome::Partial, $result->outcome);
        $this->assertSame([], $this->writeMethods($transport));
        $this->assertSame(['6'], $remoteCategories);
        $this->assertSame(0, AdobeProductCategoryAssignment::withoutWorkspaceScope()->count());
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->code === 'category_mapping_invalid_external_id',
        ));
    }

    #[Test]
    public function local_category_change_to_same_external_category_is_relation_no_op(): void
    {
        [$account, $product, $variant, $link] = $this->trustedSimple('501');
        $remoteCategories = ['7'];
        $this->managedAssignment($account->workspace_id, $account->id, $link->id, '7', '501');
        $transport = $this->bindCategoryTransport($variant->sku, 501, $remoteCategories);

        $result = $this->executeSimple(
            $account->workspace_id,
            $account->id,
            $product->id,
            $variant->id,
            categoryId: 20,
            externalCategoryId: '7',
        );

        $this->assertSame(SyncLiveOutcome::Synchronized, $result->outcome);
        $this->assertSame([], $this->writeMethods($transport));
        $this->assertSame(['7'], $remoteCategories);

        $assignment = AdobeProductCategoryAssignment::withoutWorkspaceScope()->sole();
        $this->assertSame(AdobeProductCategoryAssignmentState::Managed, $assignment->state);
        $this->assertSame('7', $assignment->external_category_id);
        $this->assertSame('501', $assignment->anchor_entity_id);
    }

    #[Test]
    public function configurable_path_anchors_category_assignment_to_trusted_parent_erl(): void
    {
        [$account, $product, $link] = $this->trustedConfigurableParent('701');
        $remoteCategories = [];
        $transport = $this->bindCategoryTransport($link->external_identifier, 701, $remoteCategories);

        $result = app(AdobeProductCategoryRelationExecutor::class)->executeAfterProduct(
            aggregate: new ProductExecutionAggregate(
                productId: (string) $product->id,
                productValues: [],
                variants: [],
                sellableVariantCount: 2,
                imageInput: new ProductExecutionImageInput(ProductExecutionImageStructuralState::Valid, []),
                categoryId: 20,
            ),
            snapshot: [
                'category_mappings' => [[
                    'category_id' => 20,
                    'external_category_id' => '7',
                ]],
            ],
            semanticResult: new AdobeProductExportSemanticResult(
                findings: [],
                operations: [
                    new AdobeProductExportSemanticOperation('configurable_parent', [
                        'product_id' => (string) $product->id,
                    ]),
                ],
            ),
            currentResult: new SyncLiveProductExecutionResult(SyncLiveOutcome::Synchronized, []),
            runContext: new AdobeProductExportLiveRunContext(
                workspaceId: $account->workspace_id,
                connectorAccountId: $account->id,
                metadata: new AdobeProductExportExecutionMetadata(4, []),
                adobeBaseCurrency: 'UAH',
            ),
            writeGate: $this->gate(true),
            isConfigurablePath: true,
        );

        $this->assertSame(SyncLiveOutcome::Synchronized, $result->outcome);
        $this->assertSame(['POST'], $this->writeMethods($transport));

        $assignment = AdobeProductCategoryAssignment::withoutWorkspaceScope()->sole();
        $this->assertSame($link->id, $assignment->external_record_link_id);
        $this->assertSame('701', $assignment->anchor_entity_id);
        $this->assertSame(AdobeProductCategoryAssignmentState::Managed, $assignment->state);
    }

    #[Test]
    public function decision_b_category_set_adds_all_desired_and_removes_only_owned_relations(): void
    {
        [$account, $product, $variant, $link] = $this->trustedSimple('501');
        $remoteCategories = ['99'];
        $transport = $this->bindCategoryTransport($variant->sku, 501, $remoteCategories);

        $first = $this->executeSimpleWithClassification(
            $account->workspace_id,
            $account->id,
            $product->id,
            $variant->id,
            ['7', '8'],
        );

        $this->assertSame(SyncLiveOutcome::Synchronized, $first->outcome);
        $this->assertSame(['POST', 'POST'], $this->writeMethods($transport));
        $this->assertSame(['7', '8', '99'], $remoteCategories);
        $this->assertSame(
            ['7', '8'],
            AdobeProductCategoryAssignment::withoutWorkspaceScope()
                ->orderBy('external_category_id')
                ->pluck('external_category_id')
                ->all(),
        );

        $second = $this->executeSimpleWithClassification(
            $account->workspace_id,
            $account->id,
            $product->id,
            $variant->id,
            ['8'],
        );

        $this->assertSame(SyncLiveOutcome::Synchronized, $second->outcome);
        $this->assertSame(['POST', 'POST', 'DELETE'], $this->writeMethods($transport));
        $this->assertSame(['8', '99'], $remoteCategories);
        $this->assertSame(
            ['8'],
            AdobeProductCategoryAssignment::withoutWorkspaceScope()
                ->orderBy('external_category_id')
                ->pluck('external_category_id')
                ->all(),
        );
        $this->assertSame($link->id, AdobeProductCategoryAssignment::withoutWorkspaceScope()->sole()->external_record_link_id);
    }

    #[Test]
    public function null_local_category_removes_only_proven_managed_relation(): void
    {
        [$account, $product, $variant, $link] = $this->trustedSimple('501');
        $remoteCategories = ['6', '8'];
        $this->managedAssignment($account->workspace_id, $account->id, $link->id, '6', '501');
        $transport = $this->bindCategoryTransport($variant->sku, 501, $remoteCategories);

        $result = $this->executeSimple(
            $account->workspace_id,
            $account->id,
            $product->id,
            $variant->id,
            categoryId: null,
            externalCategoryId: null,
        );

        $this->assertSame(SyncLiveOutcome::Synchronized, $result->outcome);
        $this->assertSame(['DELETE'], $this->writeMethods($transport));
        $this->assertSame(['8'], $remoteCategories);
        $this->assertSame(0, AdobeProductCategoryAssignment::withoutWorkspaceScope()->count());
    }

    /**
     * @return array{0: ConnectorAccount, 1: Product, 2: ProductVariant, 3: ExternalRecordLink}
     */
    private function trustedSimple(string $entityId): array
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CAT-'.Str::random(8),
            'name' => 'Category runtime fixture',
            'is_active' => true,
        ]);
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CAT-VAR-'.Str::random(8),
            'is_active' => true,
        ]);
        $user = $this->createStaffUser(UserRole::Manager);
        $membership = $this->makeWorkspaceMembership($workspace, $user, true);

        $link = ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => $variant->sku,
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => $entityId,
            'established_by_workspace_user_id' => $membership->id,
            'established_at' => now(),
        ]);

        return [$account, $product, $variant, $link];
    }

    /**
     * @return array{0: ConnectorAccount, 1: Product, 2: ExternalRecordLink}
     */
    private function trustedConfigurableParent(string $entityId): array
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CAT-PARENT-'.Str::random(8),
            'name' => 'Category configurable fixture',
            'is_active' => true,
        ]);
        $user = $this->createStaffUser(UserRole::Manager);
        $membership = $this->makeWorkspaceMembership($workspace, $user, true);

        $link = ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => $product->sku,
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => $entityId,
            'established_by_workspace_user_id' => $membership->id,
            'established_at' => now(),
        ]);

        return [$account, $product, $link];
    }

    private function managedAssignment(
        string $workspaceId,
        string $accountId,
        string $linkId,
        string $externalCategoryId,
        string $entityId,
    ): AdobeProductCategoryAssignment {
        return AdobeProductCategoryAssignment::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceId,
            'connector_account_id' => $accountId,
            'external_record_link_id' => $linkId,
            'external_category_id' => $externalCategoryId,
            'state' => AdobeProductCategoryAssignmentState::Managed,
            'anchor_entity_id' => $entityId,
        ]);
    }

    private function executeSimple(
        string $workspaceId,
        string $accountId,
        int $productId,
        int $variantId,
        ?int $categoryId,
        ?string $externalCategoryId,
    ): SyncLiveProductExecutionResult {
        $snapshot = [
            'category_mappings' => $categoryId !== null && $externalCategoryId !== null
                ? [[
                    'category_id' => $categoryId,
                    'external_category_id' => $externalCategoryId,
                ]]
                : [],
        ];

        return app(AdobeProductCategoryRelationExecutor::class)->executeAfterProduct(
            aggregate: new ProductExecutionAggregate(
                productId: (string) $productId,
                productValues: [],
                variants: [],
                sellableVariantCount: 1,
                imageInput: new ProductExecutionImageInput(ProductExecutionImageStructuralState::Valid, []),
                categoryId: $categoryId,
            ),
            snapshot: $snapshot,
            semanticResult: new AdobeProductExportSemanticResult(
                findings: [],
                operations: [
                    new AdobeProductExportSemanticOperation('simple_product', [
                        'product_id' => (string) $productId,
                        'variant_id' => (string) $variantId,
                    ]),
                ],
            ),
            currentResult: new SyncLiveProductExecutionResult(SyncLiveOutcome::Synchronized, []),
            runContext: new AdobeProductExportLiveRunContext(
                workspaceId: $workspaceId,
                connectorAccountId: $accountId,
                metadata: new AdobeProductExportExecutionMetadata(4, []),
                adobeBaseCurrency: 'UAH',
            ),
            writeGate: $this->gate(true),
            isConfigurablePath: false,
        );
    }

    /**
     * @param  list<string>  $externalCategoryIds
     */
    private function executeSimpleWithClassification(
        string $workspaceId,
        string $accountId,
        int $productId,
        int $variantId,
        array $externalCategoryIds,
    ): SyncLiveProductExecutionResult {
        return app(AdobeProductCategoryRelationExecutor::class)->executeAfterProduct(
            aggregate: new ProductExecutionAggregate(
                productId: (string) $productId,
                productValues: [],
                variants: [],
                sellableVariantCount: 1,
                imageInput: new ProductExecutionImageInput(ProductExecutionImageStructuralState::Valid, []),
                categoryId: null,
            ),
            snapshot: [
                'adobe_product_classifications' => [[
                    'product_id' => (string) $productId,
                    'external_category_ids' => $externalCategoryIds,
                ]],
            ],
            semanticResult: new AdobeProductExportSemanticResult(
                findings: [],
                operations: [
                    new AdobeProductExportSemanticOperation('simple_product', [
                        'product_id' => (string) $productId,
                        'variant_id' => (string) $variantId,
                    ]),
                ],
            ),
            currentResult: new SyncLiveProductExecutionResult(SyncLiveOutcome::Synchronized, []),
            runContext: new AdobeProductExportLiveRunContext(
                workspaceId: $workspaceId,
                connectorAccountId: $accountId,
                metadata: new AdobeProductExportExecutionMetadata(4, []),
                adobeBaseCurrency: 'UAH',
            ),
            writeGate: $this->gate(true),
            isConfigurablePath: false,
        );
    }

    private function bindCategoryTransport(
        string $sku,
        int $entityId,
        array &$remoteCategories,
        bool $ambiguousPost = false,
    ): RecordingConnectorHttpTransport {
        $transport = new RecordingConnectorHttpTransport(
            function (ConnectorOutboundRequest $outbound) use (
                $sku,
                $entityId,
                &$remoteCategories,
                $ambiguousPost,
            ): ConnectorHttpResult {
                $request = $outbound->request;
                $method = $request->getMethod();
                $path = $request->getUri()->getPath();

                if ($method === 'GET' && str_contains($path, '/V1/products/')) {
                    return new ConnectorHttpResult(200, [], json_encode([
                        'id' => $entityId,
                        'sku' => $sku,
                        'type_id' => 'simple',
                        'extension_attributes' => [
                            'category_links' => array_map(
                                static fn (string $categoryId): array => [
                                    'position' => 0,
                                    'category_id' => $categoryId,
                                ],
                                $remoteCategories,
                            ),
                        ],
                    ], JSON_THROW_ON_ERROR));
                }

                if ($method === 'POST' && preg_match('#/V1/categories/([^/]+)/products$#', $path, $match) === 1) {
                    $categoryId = rawurldecode($match[1]);

                    if (! in_array($categoryId, $remoteCategories, true)) {
                        $remoteCategories[] = $categoryId;
                        sort($remoteCategories, SORT_NATURAL);
                    }

                    if ($ambiguousPost) {
                        throw new ConnectorTransportException(TransportFailureReason::ConnectionFailed);
                    }

                    return new ConnectorHttpResult(200, [], 'true');
                }

                if ($method === 'DELETE' && preg_match('#/V1/categories/([^/]+)/products/#', $path, $match) === 1) {
                    $categoryId = rawurldecode($match[1]);
                    $remoteCategories = array_values(array_filter(
                        $remoteCategories,
                        static fn (string $candidate): bool => $candidate !== $categoryId,
                    ));

                    return new ConnectorHttpResult(200, [], 'true');
                }

                throw new \RuntimeException("Unexpected category test request: {$method} {$path}");
            },
        );

        $this->app->instance(ConnectorHttpTransport::class, $transport);

        return $transport;
    }

    private function gate(bool $allowed): SyncLiveConsequentialWriteGate
    {
        return new class($allowed) implements SyncLiveConsequentialWriteGate
        {
            public function __construct(private readonly bool $allowed) {}

            public function permitsConsequentialWrite(): bool
            {
                return $this->allowed;
            }

            public function permitsProductExecution(): bool
            {
                return $this->allowed;
            }
        };
    }

    /**
     * @return list<string>
     */
    private function writeMethods(RecordingConnectorHttpTransport $transport): array
    {
        return array_values(array_map(
            static fn (ConnectorOutboundRequest $request): string => $request->request->getMethod(),
            array_filter(
                $transport->recordedRequests,
                static fn (ConnectorOutboundRequest $request): bool => in_array(
                    $request->request->getMethod(),
                    ['POST', 'DELETE'],
                    true,
                ),
            ),
        ));
    }
}
