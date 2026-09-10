<?php

namespace Tests\Feature\Sync;

use App\Enums\PriceListItemStatus;
use App\Enums\PriceListStatus;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncLiveOutcome;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Models\ConnectorAccount;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\CreateSyncConfigurationInput;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncLiveAdmissionService;
use App\Support\Connectors\AdobePaaS\AdobeAttributeMetadata;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectorAdapter;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionMetadata;
use App\Support\Connectors\AdobePaaS\AdobeProductExportLiveCapability;
use App\Support\Connectors\AdobePaaS\AdobeProductExportLiveRunContext;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableParentSkuGenerator;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductCommandRequestFactory;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductMediaApiLimits;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductMediaCommandEvidence;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductMediaDesiredStateBuilder;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductMediaEntryExecutor;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductMediaLiveExecutor;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductMediaMetadataComparator;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductMediaOutcomeComposer;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductMediaRemoteStateClient;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductMediaRole;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductMediaTargetResolver;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductRemoteMediaMetadataReader;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductSourceImageFetcher;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductSourceImageFetchLimits;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductSourceImageValidator;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\Curl\CurlClientFactory;
use App\Support\Connectors\Transport\Internal\ConnectorDestinationResolverImpl;
use App\Support\Connectors\Transport\Internal\ConnectorRequestSenderImpl;
use App\Support\Connectors\Transport\SsrfSafeConnectorHttpTransport;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Exceptions\SyncLiveAdmissionException;
use App\Support\Sync\Live\SyncLiveFinding;
use App\Support\Sync\Live\SyncLiveProductExecutionResult;
use App\Support\Sync\Preview\ProductExecutionAggregate;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
use App\Support\Sync\Preview\ProductExecutionImageInput;
use App\Support\Sync\Preview\ProductExecutionImageSourceEntry;
use App\Support\Sync\Preview\ProductExecutionImageStructuralState;
use App\Support\Sync\SyncExternalContext;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeConfigurableCommandTestFixtures;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeProductCommandTestFixtures;
use Tests\Support\Connectors\AdobePaaS\Media\AdobeProductMediaTestFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\Support\Sync\SyncLiveConsequentialWriteGateStub;
use Tests\TestCase;
use Tests\Unit\Connectors\Transport\FakeDnsResolver;
use Tests\Unit\Connectors\Transport\Support\FakeMonotonicClock;

class Stage3D1AdobeMediaLiveTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();
    }

    #[Test]
    public function declaration_order_is_preserved_in_media_evidence(): void
    {
        $store = $this->newMediaStore();
        [$executor, , $sourceTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('MEDIA-ORDER-SKU', $store),
            sourceResponder: $this->sequentialSourceResponder([
                AdobeProductMediaTestFixtures::jpegBytes(),
                AdobeProductMediaTestFixtures::pngBytes(),
                AdobeProductMediaTestFixtures::gifBytes(),
            ]),
        );

        $aggregate = $this->aggregateWithImages([
            'https://source.test/one.jpg',
            'https://source.test/two.png',
            'https://source.test/three.gif',
        ]);

        $result = $this->executeMediaAfterSynchronizedCore($executor, $aggregate, 'MEDIA-ORDER-SKU');

        $indices = $this->mediaDeclarationIndices($result);
        $this->assertSame([0, 1, 2], $indices);
        $this->assertSame(3, $sourceTransport->sendCount);
    }

    #[Test]
    public function empty_images_leave_core_result_unchanged(): void
    {
        $core = new SyncLiveProductExecutionResult(
            outcome: SyncLiveOutcome::Partial,
            findings: [new SyncLiveFinding(code: 'core_only', subject: '1')],
        );

        $result = app(AdobeProductMediaLiveExecutor::class)->executeAfterCoreProduct(
            $this->aggregateWithImages([]),
            $this->simpleSemanticResult('EMPTY-SKU'),
            $core,
            $this->runContext(),
            new SyncLiveConsequentialWriteGateStub(true),
            isConfigurablePath: false,
        );

        $this->assertSame(SyncLiveOutcome::Partial, $result->outcome);
        $this->assertSame(['core_only'], array_map(static fn (SyncLiveFinding $f): string => $f->code, $result->findings));
    }

    #[Test]
    public function malformed_image_entry_yields_local_known_not_applied(): void
    {
        [$executor] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->emptyGalleryAdobeResponder('MALFORMED-SKU'),
        );

        $aggregate = new ProductExecutionAggregate(
            productId: '1',
            productValues: [],
            variants: [],
            sellableVariantCount: 1,
            imageInput: new ProductExecutionImageInput(
                ProductExecutionImageStructuralState::Valid,
                [
                    new ProductExecutionImageSourceEntry(0, null, isMalformed: true),
                ],
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore($executor, $aggregate, 'MALFORMED-SKU');
        $evidence = $this->mediaEvidenceForIndex($result, 0);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied->value, $evidence['applied_state_knowledge']);
        $this->assertSame('malformed_image_declaration', $evidence['reason_code']);
    }

    #[Test]
    public function core_executes_before_media_and_skips_source_fetch_when_core_not_applied(): void
    {
        $sourceTransport = new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(200, ['Content-Type' => ['image/jpeg']], AdobeProductMediaTestFixtures::jpegBytes()),
        );

        $this->bindCapabilityWithSourceTransport($sourceTransport, 'NO-PRICE-SKU');

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createSimpleProductWithoutPrice($workspace, 'NO-PRICE-SKU', [
            'https://source.test/never.jpg',
        ]);

        $capability = app(AdobeProductExportLiveCapability::class);
        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $this->simpleSnapshot(),
        )[0];

        $result = $capability->executeProduct(
            $aggregate,
            $this->simpleSnapshot(),
            $this->runContext($workspace, $account),
            new SyncLiveConsequentialWriteGateStub(true),
        );

        $this->assertSame(SyncLiveOutcome::NotApplied, $result->outcome);
        $this->assertSame(0, $sourceTransport->sendCount);
        $this->assertTrue(collect($result->findings)->contains(
            static fn (SyncLiveFinding $finding): bool => in_array($finding->code, ['command_evidence', 'price_unavailable'], true),
        ));
    }

    #[Test]
    public function broken_gallery_does_not_run_when_core_product_is_fail_closed(): void
    {
        $this->bindCapabilityWithSourceTransport(new RecordingConnectorHttpTransport(
            fn (ConnectorOutboundRequest $request): ConnectorHttpResult => match (true) {
                str_contains((string) $request->request->getUri(), 'good.jpg') => new ConnectorHttpResult(
                    200,
                    ['Content-Type' => ['image/jpeg']],
                    AdobeProductMediaTestFixtures::jpegBytes(),
                ),
                default => new ConnectorHttpResult(404, [], 'missing'),
            },
        ));

        $transport = $this->bindAdobeTransport($this->simpleProductAdobeResponder('CORE-GALLERY-SKU'));

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createSimplePricedProduct($workspace, 'CORE-GALLERY-SKU', 100.0, [
            'https://source.test/good.jpg',
            'https://source.test/broken.jpg',
        ]);

        $result = app(AdobeProductExportLiveCapability::class)->executeProduct(
            app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
                (string) $workspace->id,
                [(string) $product->id],
                $this->simpleSnapshot(),
            )[0],
            $this->simpleSnapshot(),
            $this->runContext($workspace, $account),
            new SyncLiveConsequentialWriteGateStub(true),
        );

        $this->assertSame(SyncLiveOutcome::NotApplied, $result->outcome);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function core_applied_with_broken_gallery_yields_partial_outcome(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $store = $this->newMediaStore();
        [$executor] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('PARTIAL-SKU', $store),
            sourceResponder: fn (ConnectorOutboundRequest $request): ConnectorHttpResult => match (true) {
                str_contains((string) $request->request->getUri(), 'primary.jpg') => new ConnectorHttpResult(
                    200,
                    ['Content-Type' => ['image/jpeg']],
                    $bytes,
                ),
                default => new ConnectorHttpResult(500, [], '{}'),
            },
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages([
                'https://source.test/primary.jpg',
                'https://source.test/broken.jpg',
            ]),
            'PARTIAL-SKU',
        );

        $this->assertSame(SyncLiveOutcome::Partial, $result->outcome);
        $this->assertSame(
            AdobeProductAppliedStateKnowledge::KnownNotApplied->value,
            $this->mediaEvidenceForIndex($result, 1)['applied_state_knowledge'],
        );
    }

    #[Test]
    public function broken_primary_does_not_promote_second_image_to_primary(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $store = $this->newMediaStore();
        [$executor] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('NO-PROMOTE-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $aggregate = new ProductExecutionAggregate(
            productId: '1',
            productValues: [],
            variants: [],
            sellableVariantCount: 1,
            imageInput: new ProductExecutionImageInput(
                ProductExecutionImageStructuralState::Valid,
                [
                    new ProductExecutionImageSourceEntry(0, null, isMalformed: true),
                    new ProductExecutionImageSourceEntry(1, 'https://source.test/second.jpg'),
                ],
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore($executor, $aggregate, 'NO-PROMOTE-SKU');
        $second = $this->mediaEvidenceForIndex($result, 1);

        $this->assertSame('gallery', $second['role']);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied->value, $second['applied_state_knowledge']);
    }

    #[Test]
    public function source_image_fetch_uses_connector_http_transport(): void
    {
        $transport = new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                AdobeProductMediaTestFixtures::jpegBytes(),
            ),
        );

        $fetcher = new AdobeProductSourceImageFetcher($transport, new AdobeProductSourceImageValidator);
        $result = $fetcher->fetchAndValidate('https://images.example.test/photo.jpg', 0, AdobeProductMediaRole::Primary);

        $this->assertTrue($result->accepted);
        $this->assertInstanceOf(ConnectorHttpTransport::class, $transport);
        $this->assertSame(1, $transport->sendCount);
    }

    #[Test]
    public function unsigned_source_get_has_no_authorization_header(): void
    {
        $transport = new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                AdobeProductMediaTestFixtures::jpegBytes(),
            ),
        );

        (new AdobeProductSourceImageFetcher($transport, new AdobeProductSourceImageValidator))
            ->fetchAndValidate('https://images.example.test/photo.jpg', 0, AdobeProductMediaRole::Primary);

        $headers = $transport->recordedRequests[0]->request->getHeaders();
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    #[Test]
    public function ssrf_rejects_private_and_reserved_destinations(): void
    {
        $fetcher = new AdobeProductSourceImageFetcher(
            $this->ssrfSafeTransport(),
            new AdobeProductSourceImageValidator,
        );

        $result = $fetcher->fetchAndValidate('https://127.0.0.1/image.jpg', 0, AdobeProductMediaRole::Primary);

        $this->assertFalse($result->accepted);
        $this->assertSame('source_fetch_transport_failed', $result->reasonCode);
    }

    #[Test]
    public function ssrf_rejects_non_http_schemes(): void
    {
        $fetcher = new AdobeProductSourceImageFetcher(
            $this->ssrfSafeTransport(),
            new AdobeProductSourceImageValidator,
        );

        $result = $fetcher->fetchAndValidate('file:///etc/passwd', 0, AdobeProductMediaRole::Primary);

        $this->assertFalse($result->accepted);
        $this->assertSame('source_fetch_transport_failed', $result->reasonCode);
    }

    #[Test]
    public function source_redirect_is_rejected(): void
    {
        $transport = new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(302, ['Location' => ['https://evil.example/']], ''),
        );

        $result = (new AdobeProductSourceImageFetcher($transport, new AdobeProductSourceImageValidator))
            ->fetchAndValidate('https://images.example.test/redirect.jpg', 0, AdobeProductMediaRole::Primary);

        $this->assertFalse($result->accepted);
        $this->assertSame('source_redirect_rejected', $result->reasonCode);
    }

    #[Test]
    public function oversized_source_bytes_are_rejected(): void
    {
        $transport = new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                str_repeat('x', AdobeProductSourceImageFetchLimits::MAX_SOURCE_RESPONSE_BYTES + 1),
            ),
        );

        $result = (new AdobeProductSourceImageFetcher($transport, new AdobeProductSourceImageValidator))
            ->fetchAndValidate('https://images.example.test/huge.jpg', 0, AdobeProductMediaRole::Primary);

        $this->assertFalse($result->accepted);
        $this->assertSame('oversized_source_bytes', $result->reasonCode);
    }

    #[Test]
    public function invalid_image_bytes_are_rejected(): void
    {
        $transport = new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(200, ['Content-Type' => ['image/jpeg']], 'not-an-image'),
        );

        $result = (new AdobeProductSourceImageFetcher($transport, new AdobeProductSourceImageValidator))
            ->fetchAndValidate('https://images.example.test/bad.jpg', 0, AdobeProductMediaRole::Primary);

        $this->assertFalse($result->accepted);
        $this->assertSame('invalid_image_bytes', $result->reasonCode);
    }

    #[Test]
    public function actual_image_mime_wins_over_content_type_header(): void
    {
        $pngBytes = AdobeProductMediaTestFixtures::pngBytes();
        $transport = new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(200, ['Content-Type' => ['image/jpeg']], $pngBytes),
        );

        $result = (new AdobeProductSourceImageFetcher($transport, new AdobeProductSourceImageValidator))
            ->fetchAndValidate('https://images.example.test/wrong-header.png', 0, AdobeProductMediaRole::Primary);

        $this->assertTrue($result->accepted);
        $this->assertSame('image/png', $result->verifiedImage?->mimeType);
        $this->assertStringEndsWith('.png', $result->verifiedImage?->filename ?? '');
    }

    #[Test]
    public function jpeg_png_and_gif_sources_are_accepted(): void
    {
        $validator = new AdobeProductSourceImageValidator;

        foreach ([
            ['bytes' => AdobeProductMediaTestFixtures::jpegBytes(), 'mime' => 'image/jpeg', 'ext' => 'jpg'],
            ['bytes' => AdobeProductMediaTestFixtures::pngBytes(), 'mime' => 'image/png', 'ext' => 'png'],
            ['bytes' => AdobeProductMediaTestFixtures::gifBytes(), 'mime' => 'image/gif', 'ext' => 'gif'],
        ] as $index => $fixture) {
            $result = $validator->validate($fixture['bytes'], $index, AdobeProductMediaRole::Gallery);

            $this->assertTrue($result->accepted, 'Expected '.$fixture['mime'].' to be accepted.');
            $this->assertSame($fixture['mime'], $result->verifiedImage?->mimeType);
            $this->assertStringEndsWith('.'.$fixture['ext'], $result->verifiedImage?->filename ?? '');
        }
    }

    #[Test]
    public function verified_images_use_deterministic_sha256_filename(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $expected = AdobeProductMediaTestFixtures::filenameForBytes($bytes, 'jpg');

        $result = (new AdobeProductSourceImageValidator)->validate($bytes, 0, AdobeProductMediaRole::Primary);

        $this->assertSame($expected, $result->verifiedImage?->filename);
    }

    #[Test]
    public function duplicate_local_content_is_deduplicated_in_executor(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $store = $this->newMediaStore();
        [$executor, , $sourceTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('DEDUP-LOCAL-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages([
                'https://source.test/first.jpg',
                'https://source.test/second-same.jpg',
            ]),
            'DEDUP-LOCAL-SKU',
            label: 'Product Label',
        );

        $this->assertSame([0], $this->mediaDeclarationIndices($result));
        $this->assertSame(2, $sourceTransport->sendCount);
    }

    #[Test]
    public function simple_media_target_uses_simple_variant_sku(): void
    {
        $seenSku = null;
        [$executor] = $this->mediaLiveExecutorStack(
            adobeResponder: function (ConnectorOutboundRequest $request, int $count) use (&$seenSku): ConnectorHttpResult {
                $uri = (string) $request->request->getUri();
                if (preg_match('#/V1/products/([^/?]+)#', $uri, $matches) === 1) {
                    $seenSku = rawurldecode($matches[1]);
                }

                return ($this->emptyGalleryAdobeResponder('SIMPLE-TARGET-SKU'))($request, $count);
            },
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                AdobeProductMediaTestFixtures::jpegBytes(),
            ),
        );

        $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/simple.jpg']),
            'SIMPLE-TARGET-SKU',
        );

        $this->assertSame('SIMPLE-TARGET-SKU', $seenSku);
    }

    #[Test]
    public function configurable_media_target_uses_parent_sku(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CFG-PARENT',
            'name' => 'Configurable Parent',
            'is_active' => true,
            'images' => ['https://source.test/parent.jpg'],
        ]);
        $parentSku = (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id);
        $seenSku = null;

        [$executor] = $this->mediaLiveExecutorStack(
            adobeResponder: function (ConnectorOutboundRequest $request, int $count) use (&$seenSku, $parentSku): ConnectorHttpResult {
                $uri = (string) $request->request->getUri();
                if (preg_match('#/V1/products/([^/?]+)#', $uri, $matches) === 1) {
                    $seenSku = rawurldecode($matches[1]);
                }

                return ($this->emptyGalleryAdobeResponder($parentSku))($request, $count);
            },
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                AdobeProductMediaTestFixtures::jpegBytes(),
            ),
        );

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $this->simpleSnapshot(),
        )[0];

        $executor->executeAfterCoreProduct(
            $aggregate,
            $this->configurableSemanticResult($product->id),
            new SyncLiveProductExecutionResult(SyncLiveOutcome::Synchronized, []),
            $this->runContext($workspace),
            new SyncLiveConsequentialWriteGateStub(true),
            isConfigurablePath: true,
        );

        $this->assertSame($parentSku, $seenSku);
    }

    #[Test]
    public function configurable_non_synchronized_core_skips_all_media_mutation(): void
    {
        $postCount = 0;
        $transport = new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request, int $count) use (&$postCount): ConnectorHttpResult {
            if ($request->request->getMethod() === 'POST' && str_contains((string) $request->request->getUri(), '/media')) {
                $postCount++;
            }

            return new ConnectorHttpResult(500, [], '{}');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CFG-NOMEDIA',
            'name' => 'Configurable',
            'is_active' => true,
            'images' => ['https://source.test/ignored.jpg'],
        ]);

        $result = app(AdobeProductMediaLiveExecutor::class)->executeAfterCoreProduct(
            app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
                (string) $workspace->id,
                [(string) $product->id],
                $this->simpleSnapshot(),
            )[0],
            $this->configurableSemanticResult($product->id),
            new SyncLiveProductExecutionResult(SyncLiveOutcome::Partial, []),
            $this->runContext($workspace, $account),
            new SyncLiveConsequentialWriteGateStub(true),
            isConfigurablePath: true,
        );

        $this->assertSame(SyncLiveOutcome::Partial, $result->outcome);
        $this->assertSame(0, $postCount);
    }

    #[Test]
    public function metadata_reader_parses_official_gallery_shape(): void
    {
        $filename = AdobeProductMediaTestFixtures::filenameForBytes(AdobeProductMediaTestFixtures::jpegBytes(), 'jpg');
        $index = (new AdobeProductRemoteMediaMetadataReader)->read(
            AdobeProductMediaTestFixtures::remoteProductPayloadWithGallery('SKU', [
                AdobeProductMediaTestFixtures::remoteMediaMetadataEntry(10, '/'.$filename, 'Label', 1, ['image', 'small_image', 'thumbnail']),
            ]),
        );

        $this->assertTrue($index->isTrusted());
        $this->assertCount(1, $index->entries);
        $this->assertSame(10, $index->entries[0]->entryId);
        $this->assertSame('/'.$filename, $index->entries[0]->file);
    }

    #[Test]
    public function metadata_reader_malformed_gallery_fails_closed(): void
    {
        $index = (new AdobeProductRemoteMediaMetadataReader)->read([
            'sku' => 'SKU',
            'media_gallery_entries' => 'not-an-array',
        ]);

        $this->assertFalse($index->isTrusted());
        $this->assertSame('malformed_media_gallery_entries', $index->reasonCode);
    }

    #[Test]
    public function metadata_reader_rejects_more_than_fifty_entries(): void
    {
        $entries = [];
        for ($i = 1; $i <= 51; $i++) {
            $entries[] = AdobeProductMediaTestFixtures::remoteMediaMetadataEntry($i, '/file-'.$i.'.jpg', 'Label', $i);
        }

        $index = (new AdobeProductRemoteMediaMetadataReader)->read(
            AdobeProductMediaTestFixtures::remoteProductPayloadWithGallery('SKU', $entries),
        );

        $this->assertFalse($index->isTrusted());
        $this->assertSame('remote_media_metadata_exceeds_bounded_scan', $index->reasonCode);
    }

    #[Test]
    public function malformed_whole_collection_yields_partial_without_http(): void
    {
        [$executor, $adobeTransport, $sourceTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->emptyGalleryAdobeResponder('MALFORMED-COLLECTION-SKU'),
        );

        $aggregate = new ProductExecutionAggregate(
            productId: '1',
            productValues: [],
            variants: [],
            sellableVariantCount: 1,
            imageInput: new ProductExecutionImageInput(
                ProductExecutionImageStructuralState::Malformed,
                [],
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore($executor, $aggregate, 'MALFORMED-COLLECTION-SKU');

        $this->assertSame(SyncLiveOutcome::Partial, $result->outcome);
        $this->assertSame(
            'malformed_image_collection',
            $this->mediaEvidenceForIndex($result, 0)['reason_code'],
        );
        $this->assertSame(0, $sourceTransport->sendCount);
        $this->assertSame(0, $adobeTransport->sendCount);
    }

    #[Test]
    public function all_invalid_local_sources_perform_zero_adobe_media_reads(): void
    {
        [$executor, $adobeTransport, $sourceTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->emptyGalleryAdobeResponder('ALL-INVALID-SKU'),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(500, [], '{}'),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages([
                'https://source.test/broken-one.jpg',
                'https://source.test/broken-two.jpg',
            ]),
            'ALL-INVALID-SKU',
        );

        $this->assertSame(SyncLiveOutcome::Partial, $result->outcome);
        $this->assertSame(2, $sourceTransport->sendCount);
        $this->assertSame(0, $adobeTransport->sendCount);
    }

    #[Test]
    public function malformed_source_uri_rejects_without_secret_leakage(): void
    {
        $secretMarker = 'SECRET_LEAK_MARKER_XYZ';
        $fetcher = new AdobeProductSourceImageFetcher(
            new RecordingConnectorHttpTransport(
                fn (): ConnectorHttpResult => new ConnectorHttpResult(200, [], 'never-called'),
            ),
            new AdobeProductSourceImageValidator,
        );

        $result = $fetcher->fetchAndValidate(
            'https://user:'.$secretMarker.'@',
            0,
            AdobeProductMediaRole::Primary,
        );

        $this->assertFalse($result->accepted);
        $this->assertSame('source_reference_invalid', $result->reasonCode);
        $this->assertStringNotContainsString($secretMarker, $result->reasonCode);

        [$executor] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->emptyGalleryAdobeResponder('URI-LEAK-SKU'),
        );

        $aggregate = new ProductExecutionAggregate(
            productId: '1',
            productValues: [],
            variants: [],
            sellableVariantCount: 1,
            imageInput: new ProductExecutionImageInput(
                ProductExecutionImageStructuralState::Valid,
                [
                    new ProductExecutionImageSourceEntry(0, 'https://user:'.$secretMarker.'@'),
                ],
            ),
        );

        $liveResult = $this->executeMediaAfterSynchronizedCore($executor, $aggregate, 'URI-LEAK-SKU');
        $encoded = json_encode($liveResult->findings, JSON_THROW_ON_ERROR);

        $this->assertSame(
            'source_reference_invalid',
            $this->mediaEvidenceForIndex($liveResult, 0)['reason_code'],
        );
        $this->assertStringNotContainsString($secretMarker, $encoded);
    }

    #[Test]
    public function source_and_adobe_media_limits_are_distinct(): void
    {
        $this->assertSame(5.0, AdobeProductSourceImageFetchLimits::CONNECT_TIMEOUT_SECONDS);
        $this->assertSame(20.0, AdobeProductSourceImageFetchLimits::TOTAL_TIMEOUT_SECONDS);
        $this->assertSame(10 * 1024 * 1024, AdobeProductSourceImageFetchLimits::MAX_SOURCE_RESPONSE_BYTES);

        $this->assertSame(10.0, AdobeProductMediaApiLimits::CONNECT_TIMEOUT_SECONDS);
        $this->assertSame(60.0, AdobeProductMediaApiLimits::TOTAL_TIMEOUT_SECONDS);
        $this->assertSame(16 * 1024 * 1024, AdobeProductMediaApiLimits::MAX_INDIVIDUAL_MEDIA_GET_RESPONSE_BYTES);
        $this->assertSame(2 * 1024 * 1024, AdobeProductMediaApiLimits::MAX_MUTATION_RESPONSE_BYTES);
        $this->assertNotSame(
            AdobeProductSourceImageFetchLimits::CONNECT_TIMEOUT_SECONDS,
            AdobeProductMediaApiLimits::CONNECT_TIMEOUT_SECONDS,
        );
    }

    #[Test]
    public function non_image_entries_do_not_count_toward_fifty_image_bound(): void
    {
        $entries = [];
        for ($i = 1; $i <= 51; $i++) {
            $entries[] = AdobeProductMediaTestFixtures::remoteExternalVideoMetadataEntry(
                $i,
                '/video-'.$i.'.mp4',
                'Video '.$i,
                $i,
            );
        }
        $entries[] = AdobeProductMediaTestFixtures::remoteMediaMetadataEntry(900, '/only-image.jpg', 'Image', 900);

        $index = (new AdobeProductRemoteMediaMetadataReader)->read(
            AdobeProductMediaTestFixtures::remoteProductPayloadWithGallery('SKU', $entries),
        );

        $this->assertTrue($index->isTrusted());
        $this->assertCount(1, $index->entries);
    }

    #[Test]
    public function external_video_entries_are_ignored_by_e14_image_reconciliation(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $filename = AdobeProductMediaTestFixtures::filenameForBytes($bytes, 'jpg');
        $store = $this->newMediaStore();
        $store->addGalleryOnlyEntry(
            AdobeProductMediaTestFixtures::remoteExternalVideoMetadataEntry(
                501,
                '/'.$filename,
                'Video Preview',
                1,
            ),
        );

        [$executor, $adobeTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('IGNORE-VIDEO-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/new.jpg']),
            'IGNORE-VIDEO-SKU',
            label: 'Product Label',
        );

        $this->assertSame(SyncLiveOutcome::Synchronized, $result->outcome);
        $this->assertFalse($this->adobeRequestsIncludeIndividualMediaGet($adobeTransport, 501));
        $this->assertFalse(collect($adobeTransport->recordedRequests)->contains(
            fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'PUT'
                && str_contains((string) $request->request->getUri(), '/media/501'),
        ));
    }

    #[Test]
    public function external_video_same_content_does_not_become_image_update_candidate(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $filename = AdobeProductMediaTestFixtures::filenameForBytes($bytes, 'jpg');
        $store = $this->newMediaStore();
        $store->addGalleryOnlyEntry(
            AdobeProductMediaTestFixtures::remoteExternalVideoMetadataEntry(
                777,
                '/'.$filename,
                'Same Filename Video',
                1,
            ),
        );

        [$executor, $adobeTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('VIDEO-COLLISION-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/local.jpg']),
            'VIDEO-COLLISION-SKU',
            label: 'Product Label',
        );

        $this->assertSame('media_post_reconciled', $this->mediaEvidenceForIndex($result, 0)['reason_code']);
        $this->assertFalse($this->adobeRequestsIncludeIndividualMediaGet($adobeTransport, 777));
        $this->assertTrue(collect($adobeTransport->recordedRequests)->contains(
            fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'POST'
                && str_contains((string) $request->request->getUri(), '/media'),
        ));
    }

    #[Test]
    public function post_create_accepts_official_scalar_gallery_id_response(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $store = $this->newMediaStore();
        $store->returnScalarCreateId = true;
        [$executor, $adobeTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('SCALAR-POST-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/scalar.jpg']),
            'SCALAR-POST-SKU',
            label: 'Product Label',
        );

        $postResponses = collect($adobeTransport->recordedRequests)
            ->filter(fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'POST'
                && str_contains((string) $request->request->getUri(), '/media'));

        $this->assertSame(1, $postResponses->count());
        $this->assertSame(
            'media_post_reconciled',
            $this->mediaEvidenceForIndex($result, 0)['reason_code'],
        );
        $this->assertSame(1, $this->mediaEvidenceForIndex($result, 0)['reconciliation_get_attempts']);
    }

    #[Test]
    public function put_reconciliation_uses_exactly_one_individual_get(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $filename = AdobeProductMediaTestFixtures::filenameForBytes($bytes, 'jpg');
        $store = $this->newMediaStore($bytes, $filename, entryId: 88, label: 'Old Label', position: 1);

        [$executor, $adobeTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('PUT-GET-COUNT-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/put-count.jpg']),
            'PUT-GET-COUNT-SKU',
            label: 'New Label',
        );

        $this->assertSame(1, $this->mediaEvidenceForIndex($result, 0)['reconciliation_get_attempts']);
        $this->assertGreaterThanOrEqual(1, $this->countAdobeIndividualMediaGets($adobeTransport, 'PUT-GET-COUNT-SKU'));
    }

    #[Test]
    public function ambiguous_post_discovery_reports_actual_get_count(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $store = $this->newMediaStore();
        $store->omitCreateResponseId = true;
        [$executor, $adobeTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('DISCOVERY-GET-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/discovery.jpg']),
            'DISCOVERY-GET-SKU',
            label: 'Product Label',
        );

        $this->assertSame(
            'media_post_inconclusive_body_reconciled',
            $this->mediaEvidenceForIndex($result, 0)['reason_code'],
        );
        $this->assertSame(2, $this->mediaEvidenceForIndex($result, 0)['reconciliation_get_attempts']);
        $this->assertSame(2, $this->countAdobeProductGets($adobeTransport, 'DISCOVERY-GET-SKU'));
        $this->assertSame(1, $this->countAdobeIndividualMediaGets($adobeTransport, 'DISCOVERY-GET-SKU'));
    }

    #[Test]
    public function ambiguous_post_discovery_with_multiple_filename_candidates_stops_without_individual_get(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $filename = AdobeProductMediaTestFixtures::filenameForBytes($bytes, 'jpg');
        $store = $this->newMediaStore();
        $store->omitCreateResponseId = true;
        $store->injectDuplicateFilenameCandidateAfterPost = true;
        [$executor, $adobeTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('MULTI-FILENAME-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/multi-filename.jpg']),
            'MULTI-FILENAME-SKU',
            label: 'Product Label',
        );

        $evidence = $this->mediaEvidenceForIndex($result, 0);
        $this->assertSame(SyncLiveOutcome::Ambiguous, $result->outcome);
        $this->assertSame(
            AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous->value,
            $evidence['applied_state_knowledge'],
        );
        $this->assertSame('media_post_reconciliation_multiple_filename_candidates', $evidence['reason_code']);
        $this->assertSame(1, $evidence['consequential_write_attempts']);
        $this->assertSame(1, $evidence['reconciliation_get_attempts']);
        $this->assertSame(0, $this->countAdobeIndividualMediaGets($adobeTransport, 'MULTI-FILENAME-SKU'));
        $this->assertSame(1, collect($adobeTransport->recordedRequests)->filter(
            fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'POST'
                && str_contains((string) $request->request->getUri(), '/media'),
        )->count());
        $this->assertFalse(collect($adobeTransport->recordedRequests)->contains(
            fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'PUT'
                && str_contains((string) $request->request->getUri(), '/media'),
        ));
        $this->assertFalse(collect($adobeTransport->recordedRequests)->contains(
            fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'DELETE'
                && str_contains((string) $request->request->getUri(), '/media'),
        ));
    }

    #[Test]
    public function matching_remote_content_and_metadata_produces_no_op(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $filename = AdobeProductMediaTestFixtures::filenameForBytes($bytes, 'jpg');
        $store = $this->newMediaStore($bytes, $filename, entryId: 42, label: 'Product Label', position: 1);

        [$executor, $adobeTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('NOOP-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/noop.jpg']),
            'NOOP-SKU',
            label: 'Product Label',
        );

        $this->assertSame(SyncLiveOutcome::Synchronized, $result->outcome);
        $this->assertSame(
            'content_and_metadata_match_no_op',
            $this->mediaEvidenceForIndex($result, 0)['reason_code'],
        );
        $this->assertFalse(collect($adobeTransport->recordedRequests)->contains(
            fn (ConnectorOutboundRequest $request): bool => in_array($request->request->getMethod(), ['POST', 'PUT'], true)
                && str_contains((string) $request->request->getUri(), '/media'),
        ));
    }

    #[Test]
    public function same_filename_different_content_is_ambiguous(): void
    {
        $remoteBytes = AdobeProductMediaTestFixtures::pngBytes();
        $localBytes = AdobeProductMediaTestFixtures::jpegBytes();
        $filename = AdobeProductMediaTestFixtures::filenameForBytes($localBytes, 'jpg');
        $store = $this->newMediaStore($remoteBytes, $filename, entryId: 55, label: 'Product Label', position: 1);

        [$executor] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('COLLISION-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $localBytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/collision.jpg']),
            'COLLISION-SKU',
            label: 'Product Label',
        );

        $this->assertSame(SyncLiveOutcome::Ambiguous, $result->outcome);
        $this->assertSame(
            'filename_content_collision',
            $this->mediaEvidenceForIndex($result, 0)['reason_code'],
        );
    }

    #[Test]
    public function duplicate_matching_remote_content_is_ambiguous(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $filename = AdobeProductMediaTestFixtures::filenameForBytes($bytes, 'jpg');
        $store = $this->newMediaStore($bytes, $filename, entryId: 70, label: 'Product Label', position: 1);
        $store->addEntry($bytes, $filename.'-dup', entryId: 71, label: 'Product Label', position: 2);

        [$executor] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('DUP-MATCH-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/dup.jpg']),
            'DUP-MATCH-SKU',
            label: 'Product Label',
        );

        $this->assertSame(SyncLiveOutcome::Ambiguous, $result->outcome);
        $this->assertSame(
            'ambiguous_matching_remote_media',
            $this->mediaEvidenceForIndex($result, 0)['reason_code'],
        );
    }

    #[Test]
    public function missing_remote_content_issues_at_most_one_post(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $store = $this->newMediaStore();
        [$executor, $adobeTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('POST-ONCE-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/new.jpg']),
            'POST-ONCE-SKU',
            label: 'Product Label',
        );

        $postCount = collect($adobeTransport->recordedRequests)->filter(
            fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'POST'
                && str_contains((string) $request->request->getUri(), '/media'),
        )->count();

        $this->assertSame(1, $postCount);
        $this->assertSame(
            'media_post_reconciled',
            $this->mediaEvidenceForIndex($result, 0)['reason_code'],
        );
    }

    #[Test]
    public function metadata_drift_issues_at_most_one_metadata_only_put(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $filename = AdobeProductMediaTestFixtures::filenameForBytes($bytes, 'jpg');
        $store = $this->newMediaStore($bytes, $filename, entryId: 88, label: 'Old Label', position: 1);

        [$executor, $adobeTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('PUT-ONCE-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/put.jpg']),
            'PUT-ONCE-SKU',
            label: 'New Label',
        );

        $puts = collect($adobeTransport->recordedRequests)->filter(
            fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'PUT'
                && str_contains((string) $request->request->getUri(), '/media'),
        );

        $this->assertSame(1, $puts->count());
        $putBody = json_decode((string) $puts->first()->request->getBody(), true);
        $this->assertArrayNotHasKey('content', $putBody['entry'] ?? []);
        $this->assertSame(
            'media_put_reconciled',
            $this->mediaEvidenceForIndex($result, 0)['reason_code'],
        );
    }

    #[Test]
    public function post_mutation_reconciles_to_applied_when_remote_state_matches(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $store = $this->newMediaStore();
        [$executor] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('POST-RECON-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/post.jpg']),
            'POST-RECON-SKU',
            label: 'Product Label',
        );

        $evidence = $this->mediaEvidenceForIndex($result, 0);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied->value, $evidence['applied_state_knowledge']);
        $this->assertSame(1, $evidence['consequential_write_attempts']);
        $this->assertSame(1, $evidence['reconciliation_get_attempts']);
    }

    #[Test]
    public function put_mutation_reconciles_to_applied_when_metadata_matches(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $filename = AdobeProductMediaTestFixtures::filenameForBytes($bytes, 'jpg');
        $store = $this->newMediaStore($bytes, $filename, entryId: 90, label: 'Stale', position: 1);

        [$executor] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('PUT-RECON-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/put-recon.jpg']),
            'PUT-RECON-SKU',
            label: 'Fresh Label',
        );

        $evidence = $this->mediaEvidenceForIndex($result, 0);
        $this->assertSame('media_put_reconciled', $evidence['reason_code']);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied->value, $evidence['applied_state_knowledge']);
    }

    #[Test]
    public function successful_media_mutation_does_not_issue_second_write(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $store = $this->newMediaStore();
        [$executor, $adobeTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('ONE-WRITE-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/once.jpg']),
            'ONE-WRITE-SKU',
            label: 'Product Label',
        );

        $mutations = collect($adobeTransport->recordedRequests)->filter(
            fn (ConnectorOutboundRequest $request): bool => in_array($request->request->getMethod(), ['POST', 'PUT'], true)
                && str_contains((string) $request->request->getUri(), '/media'),
        );

        $this->assertSame(1, $mutations->count());
    }

    #[Test]
    public function local_known_not_applied_on_first_entry_allows_later_media_processing(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $store = $this->newMediaStore();
        [$executor] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('LATER-MEDIA-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $aggregate = new ProductExecutionAggregate(
            productId: '1',
            productValues: [],
            variants: [],
            sellableVariantCount: 1,
            imageInput: new ProductExecutionImageInput(
                ProductExecutionImageStructuralState::Valid,
                [
                    new ProductExecutionImageSourceEntry(0, null, isMalformed: true),
                    new ProductExecutionImageSourceEntry(1, 'https://source.test/later.jpg'),
                ],
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore($executor, $aggregate, 'LATER-MEDIA-SKU', label: 'Product Label');

        $this->assertSame(
            'malformed_image_declaration',
            $this->mediaEvidenceForIndex($result, 0)['reason_code'],
        );
        $this->assertSame(
            'media_post_reconciled',
            $this->mediaEvidenceForIndex($result, 1)['reason_code'],
        );
    }

    #[Test]
    public function remote_unknown_on_first_entry_stops_later_media_writes(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $filename = AdobeProductMediaTestFixtures::filenameForBytes($bytes, 'jpg');
        $store = $this->newMediaStore($bytes, $filename, entryId: 70, label: 'Product Label', position: 1);
        $store->addEntry($bytes, $filename.'-dup', entryId: 71, label: 'Product Label', position: 2);

        [$executor, , $sourceTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('STOP-WRITES-SKU', $store),
            sourceResponder: $this->sequentialSourceResponder([$bytes, AdobeProductMediaTestFixtures::pngBytes()]),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages([
                'https://source.test/first.jpg',
                'https://source.test/second.png',
            ]),
            'STOP-WRITES-SKU',
            label: 'Product Label',
        );

        $this->assertSame(SyncLiveOutcome::Ambiguous, $result->outcome);
        $this->assertSame(
            'ambiguous_matching_remote_media',
            $this->mediaEvidenceForIndex($result, 0)['reason_code'],
        );
        $this->assertSame([0], $this->mediaDeclarationIndices($result));
        $this->assertSame(1, $sourceTransport->sendCount);
    }

    #[Test]
    public function lease_expiry_before_post_yields_not_applied_without_http_post(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $store = $this->newMediaStore();
        [$executor, $adobeTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('LEASE-POST-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/lease-post.jpg']),
            'LEASE-POST-SKU',
            label: 'Product Label',
            writeGate: new SyncLiveConsequentialWriteGateStub(permitsConsequentialWrite: false),
        );

        $this->assertSame(
            'writer_lease_expired_before_media_post',
            $this->mediaEvidenceForIndex($result, 0)['reason_code'],
        );
        $this->assertFalse(collect($adobeTransport->recordedRequests)->contains(
            fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'POST'
                && str_contains((string) $request->request->getUri(), '/media'),
        ));
    }

    #[Test]
    public function lease_expiry_before_put_yields_not_applied_without_http_put(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $filename = AdobeProductMediaTestFixtures::filenameForBytes($bytes, 'jpg');
        $store = $this->newMediaStore($bytes, $filename, entryId: 95, label: 'Old', position: 1);

        [$executor, $adobeTransport] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('LEASE-PUT-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/lease-put.jpg']),
            'LEASE-PUT-SKU',
            label: 'New Label',
            writeGate: new SyncLiveConsequentialWriteGateStub(permitsConsequentialWrite: false),
        );

        $this->assertSame(
            'writer_lease_expired_before_media_put',
            $this->mediaEvidenceForIndex($result, 0)['reason_code'],
        );
        $this->assertFalse(collect($adobeTransport->recordedRequests)->contains(
            fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'PUT'
                && str_contains((string) $request->request->getUri(), '/media'),
        ));
    }

    #[Test]
    public function reconciliation_after_mutation_attempt_is_ambiguous_when_post_body_unusable(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $store = $this->newMediaStore();
        $responder = $this->mediaStoreResponder('RECON-FAIL-SKU', $store);
        [$executor] = $this->mediaLiveExecutorStack(
            adobeResponder: function (ConnectorOutboundRequest $request, int $count) use ($responder): ConnectorHttpResult {
                if ($request->request->getMethod() === 'POST' && str_contains((string) $request->request->getUri(), '/media')) {
                    return new ConnectorHttpResult(500, [], '{}');
                }

                return $responder($request, $count);
            },
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/recon-fail.jpg']),
            'RECON-FAIL-SKU',
            label: 'Product Label',
        );

        $this->assertSame(SyncLiveOutcome::Ambiguous, $result->outcome);
        $this->assertStringContainsString(
            'media_post',
            $this->mediaEvidenceForIndex($result, 0)['reason_code'],
        );
    }

    #[Test]
    public function partial_and_ambiguous_media_evidence_compose_correct_outcomes(): void
    {
        $composer = new AdobeProductMediaOutcomeComposer;
        $core = new SyncLiveProductExecutionResult(SyncLiveOutcome::Synchronized, []);

        $partial = $composer->compose($core, [
            new AdobeProductMediaCommandEvidence(0, AdobeProductMediaRole::Primary, AdobeProductAppliedStateKnowledge::KnownApplied, 'ok'),
            new AdobeProductMediaCommandEvidence(1, AdobeProductMediaRole::Gallery, AdobeProductAppliedStateKnowledge::KnownNotApplied, 'bad'),
        ]);
        $ambiguous = $composer->compose($core, [
            new AdobeProductMediaCommandEvidence(0, AdobeProductMediaRole::Primary, AdobeProductAppliedStateKnowledge::KnownApplied, 'ok'),
            new AdobeProductMediaCommandEvidence(1, AdobeProductMediaRole::Gallery, AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, 'uncertain'),
        ]);

        $this->assertSame(SyncLiveOutcome::Partial, $partial->outcome);
        $this->assertSame(SyncLiveOutcome::Ambiguous, $ambiguous->outcome);
    }

    #[Test]
    public function media_findings_contain_safe_evidence_without_urls_or_base64(): void
    {
        $bytes = AdobeProductMediaTestFixtures::jpegBytes();
        $store = $this->newMediaStore();
        [$executor] = $this->mediaLiveExecutorStack(
            adobeResponder: $this->mediaStoreResponder('SAFE-SKU', $store),
            sourceResponder: fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                $bytes,
            ),
        );

        $result = $this->executeMediaAfterSynchronizedCore(
            $executor,
            $this->aggregateWithImages(['https://source.test/safe.jpg']),
            'SAFE-SKU',
            label: 'Product Label',
        );

        $encoded = json_encode($result->findings, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('https://source.test/safe.jpg', $encoded);
        $this->assertStringNotContainsString(base64_encode($bytes), $encoded);
        $this->assertStringNotContainsString('Authorization', $encoded);
    }

    #[Test]
    public function advertised_live_support_remains_false_despite_media_live_executor(): void
    {
        $adapter = new AdobePaaSConnectorAdapter;
        $account = $this->createConnectorAccount();

        $this->assertFalse($adapter->supports(
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
            SyncRunMode::Live,
        ));

        $this->assertFalse(app(ConnectorSyncSupportResolver::class)->supports(
            $account,
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
            SyncRunMode::Live,
        ));
    }

    #[Test]
    public function production_live_admission_rejects_adobe_while_live_support_is_false(): void
    {
        $account = $this->createConnectorAccount();
        $configuration = $this->prepareMappedConfiguration($account);
        $actor = $this->grantLivePermission($account->workspace);
        $this->seedCompletedPreview($account, $configuration);

        $this->expectException(SyncLiveAdmissionException::class);

        app(SyncLiveAdmissionService::class)->admit($actor, $account, $configuration->id);
    }

    /**
     * @return array{0: AdobeProductMediaLiveExecutor, 1: RecordingConnectorHttpTransport, 2: ConnectorHttpTransport}
     */
    private function mediaLiveExecutorStack(
        ?\Closure $adobeResponder = null,
        ?\Closure $sourceResponder = null,
    ): array {
        $adobeTransport = new RecordingConnectorHttpTransport(
            $adobeResponder ?? fn (): ConnectorHttpResult => new ConnectorHttpResult(500, [], '{}'),
        );
        $sourceTransport = new RecordingConnectorHttpTransport(
            $sourceResponder ?? fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                ['Content-Type' => ['image/jpeg']],
                AdobeProductMediaTestFixtures::jpegBytes(),
            ),
        );

        $validator = new AdobeProductSourceImageValidator;
        $mediaRemoteClient = new AdobeProductMediaRemoteStateClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
            $adobeTransport,
            new AdobeProductRemoteMediaMetadataReader,
            $validator,
        );

        $executor = new AdobeProductMediaLiveExecutor(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductMediaTargetResolver(new AdobeConfigurableParentSkuGenerator),
            new AdobeProductSourceImageFetcher($sourceTransport, $validator),
            new AdobeProductMediaDesiredStateBuilder,
            $mediaRemoteClient,
            new AdobeProductMediaEntryExecutor($mediaRemoteClient, new AdobeProductMediaMetadataComparator),
            new AdobeProductMediaOutcomeComposer,
        );

        return [$executor, $adobeTransport, $sourceTransport];
    }

    private function bindCapabilityWithSourceTransport(
        RecordingConnectorHttpTransport $sourceTransport,
        string $sku = 'NO-PRICE-SKU',
    ): RecordingConnectorHttpTransport {
        $adobeTransport = $this->bindAdobeTransport($this->simpleProductAdobeResponder($sku));

        $validator = new AdobeProductSourceImageValidator;
        $mediaRemoteClient = new AdobeProductMediaRemoteStateClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
            $adobeTransport,
            new AdobeProductRemoteMediaMetadataReader,
            $validator,
        );

        $this->app->instance(AdobeProductMediaLiveExecutor::class, new AdobeProductMediaLiveExecutor(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductMediaTargetResolver(new AdobeConfigurableParentSkuGenerator),
            new AdobeProductSourceImageFetcher($sourceTransport, $validator),
            new AdobeProductMediaDesiredStateBuilder,
            $mediaRemoteClient,
            new AdobeProductMediaEntryExecutor($mediaRemoteClient, new AdobeProductMediaMetadataComparator),
            new AdobeProductMediaOutcomeComposer,
        ));

        return $adobeTransport;
    }

    private function bindAdobeTransport(?\Closure $responder = null): RecordingConnectorHttpTransport
    {
        $transport = new RecordingConnectorHttpTransport(
            $responder ?? fn (): ConnectorHttpResult => new ConnectorHttpResult(500, [], '{}'),
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        return $transport;
    }

    private function executeMediaAfterSynchronizedCore(
        AdobeProductMediaLiveExecutor $executor,
        ProductExecutionAggregate $aggregate,
        string $sku,
        string $label = 'Product Label',
        ?SyncLiveConsequentialWriteGateStub $writeGate = null,
    ): SyncLiveProductExecutionResult {
        return $executor->executeAfterCoreProduct(
            $aggregate,
            $this->simpleSemanticResult($sku, $label),
            new SyncLiveProductExecutionResult(SyncLiveOutcome::Synchronized, []),
            $this->runContext(),
            $writeGate ?? new SyncLiveConsequentialWriteGateStub(true),
            isConfigurablePath: false,
        );
    }

    /**
     * @param  list<string>  $urls
     */
    private function aggregateWithImages(array $urls): ProductExecutionAggregate
    {
        return new ProductExecutionAggregate(
            productId: '1',
            productValues: [],
            variants: [],
            sellableVariantCount: 1,
            imageInput: new ProductExecutionImageInput(
                ProductExecutionImageStructuralState::Valid,
                array_map(
                    static fn (string $url, int $index): ProductExecutionImageSourceEntry => new ProductExecutionImageSourceEntry($index, $url),
                    $urls,
                    array_keys($urls),
                ),
            ),
        );
    }

    private function simpleSemanticResult(string $sku, string $name = 'Product Label'): AdobeProductExportSemanticResult
    {
        return AdobeProductCommandTestFixtures::semanticResult([
            'sku' => $sku,
            'name' => $name,
        ]);
    }

    private function configurableSemanticResult(int|string $productId): AdobeProductExportSemanticResult
    {
        return AdobeConfigurableCommandTestFixtures::configurableSemanticResult((int) $productId);
    }

    private function runContext(?Workspace $workspace = null, ?ConnectorAccount $account = null): AdobeProductExportLiveRunContext
    {
        $workspace ??= $this->defaultWorkspace();
        $account ??= $this->createConnectorAccount($workspace);

        return new AdobeProductExportLiveRunContext(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            metadata: $this->metadataFixture(),
            adobeBaseCurrency: 'UAH',
        );
    }

    private function metadataFixture(): AdobeProductExportExecutionMetadata
    {
        return new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: 4,
            attributeSets: [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
            attributes: [
                'name' => new AdobeAttributeMetadata(71, 'name', 'text', 'global', []),
                'sku' => new AdobeAttributeMetadata(74, 'sku', 'text', 'global', []),
                'status' => new AdobeAttributeMetadata(97, 'status', 'select', 'global', ['1' => 'Enabled']),
            ],
        );
    }

    /**
     * @return list<int>
     */
    private function mediaDeclarationIndices(SyncLiveProductExecutionResult $result): array
    {
        return collect($result->findings)
            ->filter(static fn (SyncLiveFinding $finding): bool => $finding->code === 'media_evidence')
            ->map(static fn (SyncLiveFinding $finding): int => (int) $finding->subject)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaEvidenceForIndex(SyncLiveProductExecutionResult $result, int $index): array
    {
        $finding = collect($result->findings)->first(
            static fn (SyncLiveFinding $finding): bool => $finding->code === 'media_evidence'
                && (int) $finding->subject === $index,
        );

        $this->assertNotNull($finding);

        return $finding->context;
    }

    private function countAdobeProductGets(RecordingConnectorHttpTransport $transport, string $sku): int
    {
        return collect($transport->recordedRequests)->filter(
            fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'GET'
                && preg_match('#/V1/products/'.preg_quote(rawurlencode($sku), '#').'$#', (string) $request->request->getUri()) === 1,
        )->count();
    }

    private function countAdobeIndividualMediaGets(RecordingConnectorHttpTransport $transport, string $sku): int
    {
        return collect($transport->recordedRequests)->filter(
            fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'GET'
                && preg_match('#/V1/products/'.preg_quote(rawurlencode($sku), '#').'/media/\d+$#', (string) $request->request->getUri()) === 1,
        )->count();
    }

    private function adobeRequestsIncludeIndividualMediaGet(RecordingConnectorHttpTransport $transport, int $entryId): bool
    {
        return collect($transport->recordedRequests)->contains(
            fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'GET'
                && str_contains((string) $request->request->getUri(), '/media/'.$entryId),
        );
    }

    private function ssrfSafeTransport(): SsrfSafeConnectorHttpTransport
    {
        return SsrfSafeConnectorHttpTransport::create(
            new ConnectorDestinationResolverImpl(new FakeDnsResolver([])),
            new ConnectorRequestSenderImpl(app(CurlClientFactory::class), true),
            new FakeMonotonicClock,
        );
    }

    private function emptyGalleryAdobeResponder(string $sku): \Closure
    {
        return function (ConnectorOutboundRequest $request, int $count) use ($sku): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();
            $method = $request->request->getMethod();

            if ($method === 'GET' && preg_match('#/V1/products/'.preg_quote(rawurlencode($sku), '#').'$#', $uri) === 1) {
                return new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(['product' => AdobeProductMediaTestFixtures::remoteProductPayloadWithGallery($sku, [])], JSON_THROW_ON_ERROR),
                );
            }

            return new ConnectorHttpResult(404, [], '{}');
        };
    }

    private function simpleProductAdobeResponder(string $sku): \Closure
    {
        return function (ConnectorOutboundRequest $request, int $count) use ($sku): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();
            $method = $request->request->getMethod();

            if ($method === 'GET' && str_contains($uri, '/V1/products/'.$sku)) {
                return new ConnectorHttpResult(
                    404,
                    [],
                    AdobeProductCommandTestFixtures::trustedMissing404Body($sku),
                );
            }

            if ($method === 'POST' && str_contains($uri, '/V1/products')) {
                return new ConnectorHttpResult(200, [], json_encode(['sku' => $sku], JSON_THROW_ON_ERROR));
            }

            if ($method === 'GET' && str_contains($uri, '/V1/products/'.rawurlencode($sku))) {
                return new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(['product' => AdobeProductMediaTestFixtures::remoteProductPayloadWithGallery($sku, [])], JSON_THROW_ON_ERROR),
                );
            }

            if (($method === 'PUT') && str_contains($uri, '/V1/products/'.$sku)) {
                return new ConnectorHttpResult(200, [], '{}');
            }

            return new ConnectorHttpResult(404, [], '{}');
        };
    }

    /**
     * @param  list<string>  $bodies
     */
    private function sequentialSourceResponder(array $bodies): \Closure
    {
        return function (ConnectorOutboundRequest $request, int $count) use ($bodies): ConnectorHttpResult {
            $index = max(0, $count - 1);
            $bytes = $bodies[$index] ?? $bodies[array_key_last($bodies)];

            return new ConnectorHttpResult(200, ['Content-Type' => ['image/jpeg']], $bytes);
        };
    }

    private function newMediaStore(
        ?string $bytes = null,
        ?string $filename = null,
        int $entryId = 100,
        string $label = 'Product Label',
        int $position = 1,
    ): InMemoryAdobeMediaStore {
        $store = new InMemoryAdobeMediaStore;

        if ($bytes !== null && $filename !== null) {
            $store->addEntry($bytes, $filename, $entryId, $label, $position);
        }

        return $store;
    }

    private function mediaStoreResponder(string $sku, InMemoryAdobeMediaStore $store): \Closure
    {
        return function (ConnectorOutboundRequest $request, int $count) use ($sku, $store): ConnectorHttpResult {
            return $store->handle($request, $sku);
        };
    }

    /**
     * @param  list<string>  $images
     * @return array{0: Product}
     */
    private function createSimplePricedProduct(Workspace $workspace, string $sku, float $price, array $images = []): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'is_active' => true,
            'images' => $images,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'is_active' => true,
            'base_price_cache' => $price,
        ]);

        $this->attachVariantPrice($workspace, $variant, $price);

        return [$product];
    }

    /**
     * @param  list<string>  $images
     * @return array{0: Product}
     */
    private function createSimpleProductWithoutPrice(Workspace $workspace, string $sku, array $images = []): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'is_active' => true,
            'images' => $images,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'is_active' => true,
        ]);

        return [$product];
    }

    private function attachVariantPrice(Workspace $workspace, ProductVariant $variant, float $price): void
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

    private function prepareMappedConfiguration(ConnectorAccount $account): SyncConfiguration
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

    private function seedCompletedPreview(ConnectorAccount $account, SyncConfiguration $configuration): void
    {
        SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'configuration_snapshot' => $this->simpleSnapshot(),
            'completed_at' => now(),
        ]);
    }

    private function grantLivePermission(Workspace $workspace): User
    {
        $actor = $this->createStaffUser(UserRole::Manager);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Live Runner',
            [WorkspacePermissions::RUN_SYNC_LIVE],
        );
        $this->assignRoleToMembership($membership, $role);

        return $actor;
    }

    /**
     * @return array<string, mixed>
     */
    private function simpleSnapshot(): array
    {
        return [
            'version' => 'platform.sync-run-input.v1',
            'data_domain' => 'products',
            'semantic_operation' => 'export',
            'external_context' => [],
            'selection' => ['mode' => 'all_products'],
            'field_mappings' => [
                ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
                ['field_binding_id' => $this->productVariantBinding('sku')->id, 'external_field_key' => 'sku'],
                ['field_binding_id' => $this->productBinding('status')->id, 'external_field_key' => 'status'],
            ],
            'connector_execution_configuration' => ['attribute_set_id' => 4],
        ];
    }
}

final class InMemoryAdobeMediaStore
{
    /** @var array<int, array{file: string, label: string, position: int, types: list<string>, bytes: string, mime: string}> */
    private array $entries = [];

    /** @var list<array<string, mixed>> */
    private array $galleryOnlyEntries = [];

    public bool $returnScalarCreateId = false;

    public bool $omitCreateResponseId = false;

    public bool $injectDuplicateFilenameCandidateAfterPost = false;

    private int $nextId = 200;

    public function addEntry(
        string $bytes,
        string $filename,
        int $entryId,
        string $label,
        int $position,
        array $types = ['image', 'small_image', 'thumbnail'],
    ): void {
        $mime = match (true) {
            str_ends_with($filename, '.png') => 'image/png',
            str_ends_with($filename, '.gif') => 'image/gif',
            default => 'image/jpeg',
        };

        $this->entries[$entryId] = [
            'file' => '/'.$filename,
            'label' => $label,
            'position' => $position,
            'types' => $types,
            'bytes' => $bytes,
            'mime' => $mime,
        ];
        $this->nextId = max($this->nextId, $entryId + 1);
    }

    /**
     * @param  array<string, mixed>  $galleryEntry
     */
    public function addGalleryOnlyEntry(array $galleryEntry): void
    {
        $this->galleryOnlyEntries[] = $galleryEntry;
    }

    public function handle(ConnectorOutboundRequest $request, string $sku): ConnectorHttpResult
    {
        $uri = (string) $request->request->getUri();
        $method = $request->request->getMethod();

        if ($method === 'GET' && preg_match('#/V1/products/'.preg_quote(rawurlencode($sku), '#').'$#', $uri) === 1) {
            $gallery = $this->galleryOnlyEntries;
            foreach ($this->entries as $entryId => $entry) {
                $gallery[] = AdobeProductMediaTestFixtures::remoteMediaMetadataEntry(
                    $entryId,
                    $entry['file'],
                    $entry['label'],
                    $entry['position'],
                    $entry['types'],
                );
            }

            return new ConnectorHttpResult(
                200,
                [],
                json_encode(['product' => AdobeProductMediaTestFixtures::remoteProductPayloadWithGallery($sku, $gallery)], JSON_THROW_ON_ERROR),
            );
        }

        if ($method === 'GET' && preg_match('#/V1/products/'.preg_quote(rawurlencode($sku), '#').'/media/(\d+)$#', $uri, $matches) === 1) {
            $entryId = (int) $matches[1];
            $entry = $this->entries[$entryId] ?? null;

            if ($entry === null) {
                return new ConnectorHttpResult(404, [], '{}');
            }

            return new ConnectorHttpResult(
                200,
                [],
                json_encode(AdobeProductMediaTestFixtures::remoteMediaContentPayload(
                    $entryId,
                    $entry['bytes'],
                    $entry['mime'],
                    basename($entry['file']),
                    $entry['label'],
                    $entry['position'],
                    $entry['types'],
                ), JSON_THROW_ON_ERROR),
            );
        }

        if ($method === 'POST' && str_contains($uri, '/V1/products/'.rawurlencode($sku).'/media')) {
            $payload = json_decode((string) $request->request->getBody(), true);
            $entry = is_array($payload) ? ($payload['entry'] ?? $payload) : [];
            $content = is_array($entry) ? ($entry['content'] ?? null) : null;
            $base64 = is_array($content) ? ($content['base64_encoded_data'] ?? '') : '';
            $bytes = base64_decode((string) $base64, true) ?: '';
            $filename = is_array($content) ? (string) ($content['name'] ?? 'upload.jpg') : 'upload.jpg';
            $entryId = $this->nextId++;
            $this->addEntry(
                $bytes,
                $filename,
                $entryId,
                (string) ($entry['label'] ?? 'Product Label'),
                (int) ($entry['position'] ?? 1),
                is_array($entry['types'] ?? null) ? $entry['types'] : ['image', 'small_image', 'thumbnail'],
            );

            if ($this->injectDuplicateFilenameCandidateAfterPost) {
                $this->galleryOnlyEntries[] = AdobeProductMediaTestFixtures::remoteMediaMetadataEntry(
                    $entryId + 1000,
                    '/'.$filename,
                    'Duplicate Filename Candidate',
                    (int) ($entry['position'] ?? 1) + 1,
                );
            }

            if ($this->omitCreateResponseId) {
                return new ConnectorHttpResult(200, [], '{}');
            }

            if ($this->returnScalarCreateId) {
                return new ConnectorHttpResult(200, [], json_encode($entryId, JSON_THROW_ON_ERROR));
            }

            return new ConnectorHttpResult(200, [], json_encode(['entry' => ['id' => $entryId]], JSON_THROW_ON_ERROR));
        }

        if ($method === 'PUT' && preg_match('#/V1/products/'.preg_quote(rawurlencode($sku), '#').'/media/(\d+)$#', $uri, $matches) === 1) {
            $entryId = (int) $matches[1];
            $payload = json_decode((string) $request->request->getBody(), true);
            $entry = is_array($payload) ? ($payload['entry'] ?? $payload) : [];

            if (isset($this->entries[$entryId]) && is_array($entry)) {
                $this->entries[$entryId]['label'] = (string) ($entry['label'] ?? $this->entries[$entryId]['label']);
                $this->entries[$entryId]['position'] = (int) ($entry['position'] ?? $this->entries[$entryId]['position']);
                if (is_array($entry['types'] ?? null)) {
                    $this->entries[$entryId]['types'] = $entry['types'];
                }
            }

            return new ConnectorHttpResult(200, [], '{}');
        }

        return new ConnectorHttpResult(404, [], '{}');
    }
}
