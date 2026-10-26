<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\BrandResource\Pages\EditBrand;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\BrandManager;
use App\Services\Media\OriginalImageUrlIngestService;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use App\Support\Media\Exceptions\MediaIngestException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class OriginalImageUrlIngestTest extends TestCase
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
        $role = $this->createRoleWithPermissions(
            $this->workspace->id,
            'Media importer',
            [WorkspacePermissions::MANAGE_PRODUCTS],
        );
        $this->assignRoleToMembership($membership, $role);
        Storage::fake('public');
    }

    #[Test]
    public function remote_file_flows_through_canonical_ingest_and_deduplicates_bytes(): void
    {
        $file = UploadedFile::fake()->image('image.png', 400, 300);
        $transport = $this->fakeTransport([
            new ConnectorHttpResult(200, ['Content-Type' => ['image/png']], file_get_contents($file->getRealPath())),
            new ConnectorHttpResult(200, ['Content-Type' => ['image/png']], file_get_contents($file->getRealPath())),
        ]);
        $url = 'https://images.example.net/catalog/logo.png';

        $one = app(OriginalImageUrlIngestService::class)->ingest($this->actor, $this->workspace, $url);
        $two = app(OriginalImageUrlIngestService::class)->ingest($this->actor, $this->workspace, $url);

        $this->assertSame($one->id, $two->id);
        $this->assertSame(1, MediaAsset::withoutWorkspaceScope()->where('workspace_id', $this->workspace->id)->count());
        Storage::disk('public')->assertExists((string) $one->storage_path);
        $this->assertNull($one->source_url);
        $this->assertCount(2, $transport->requests);
        $this->assertSame('images.example.net', $transport->requests[0]->request->getUri()->getHost());
        $this->assertSame(
            'BabyPark-MediaImporter/1.0 (https://babypark.ua)',
            $transport->requests[0]->request->getHeaderLine('User-Agent'),
        );
        $this->assertSame(
            'BabyPark-MediaImporter/1.0 (https://babypark.ua)',
            $transport->requests[1]->request->getHeaderLine('User-Agent'),
        );
    }

    #[Test]
    public function unauthorized_workspace_cannot_issue_any_network_request(): void
    {
        $transport = $this->fakeTransport([]);
        $foreign = Workspace::query()->create(['name' => 'Unauthorized media URL workspace']);

        try {
            app(OriginalImageUrlIngestService::class)->ingest($this->actor, $foreign, 'https://example.com/a.png');
            $this->fail('Expected authorization failure.');
        } catch (AuthorizationException) {
            $this->assertSame([], $transport->requests);
        }
    }

    #[Test]
    public function redirects_reenter_transport_and_https_downgrade_is_rejected(): void
    {
        $transport = $this->fakeTransport([
            new ConnectorHttpResult(302, ['location' => ['/photo.png']], ''),
            new ConnectorHttpResult(301, ['location' => ['http://169.254.169.254/metadata']], ''),
        ]);

        try {
            app(OriginalImageUrlIngestService::class)
                ->ingest($this->actor, $this->workspace, 'https://cdn.example.net/redirect');
            $this->fail('Expected blocked HTTPS downgrade.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('url', $exception->errors());
            $this->assertCount(2, $transport->requests);
            $this->assertSame('/photo.png', $transport->requests[1]->request->getUri()->getPath());
            $this->assertSame(
                $transport->requests[0]->request->getHeaderLine('User-Agent'),
                $transport->requests[1]->request->getHeaderLine('User-Agent'),
            );
        }
    }

    #[Test]
    public function invalid_urls_are_rejected_before_transport(): void
    {
        $transport = $this->fakeTransport([]);

        foreach (['file:///etc/passwd', 'https://person:secret@example.net/p.png', 'https://example.net/p.png#fragment', ''] as $url) {
            try {
                app(OriginalImageUrlIngestService::class)->ingest($this->actor, $this->workspace, $url);
                $this->fail('Expected invalid URL to be rejected.');
            } catch (ValidationException) {
                $this->assertSame([], $transport->requests);
            }
        }
    }

    #[Test]
    public function upstream_403_remains_fail_closed_without_persisting_an_asset(): void
    {
        $transport = $this->fakeTransport([
            new ConnectorHttpResult(403, ['content-type' => ['text/html']], 'Request blocked'),
        ]);

        try {
            app(OriginalImageUrlIngestService::class)->ingest(
                $this->actor,
                $this->workspace,
                'https://upload.wikimedia.org/wikipedia/commons/3/3f/Fronalpstock_big.jpg',
            );
            $this->fail('Expected non-200 response to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Сервер зображення не повернув файл. Перевірте URL.'],
                $exception->errors()['url'] ?? null,
            );
        }

        $this->assertSame(
            'BabyPark-MediaImporter/1.0 (https://babypark.ua)',
            $transport->requests[0]->request->getHeaderLine('User-Agent'),
        );
        $this->assertSame(0, MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)->count());
    }

    #[Test]
    public function invalid_remote_content_is_not_saved(): void
    {
        $this->fakeTransport([new ConnectorHttpResult(200, ['content-type' => ['image/png']], '<html>not an image</html>')]);

        $this->expectException(MediaIngestException::class);
        app(OriginalImageUrlIngestService::class)
            ->ingest($this->actor, $this->workspace, 'https://example.net/not-image.png');
    }

    #[Test]
    public function excessively_large_remote_image_reports_the_25_mp_limit_inline_in_brand_modal(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // A valid 400x300 PNG with IHDR dimensions changed to 6000x5000,
        // recalculating its header CRC. getimagesize reads the dimensions;
        // canonical Media ingest MUST reject >25MP before decoding/storing.
        // Avoid allocating a 30-million-pixel bitmap in CI.
        $file = UploadedFile::fake()->image('original.png', 400, 300);
        $png = (string) file_get_contents($file->getRealPath());
        $png = substr_replace($png, pack('N2', 6000, 5000), 16, 8);
        $png = substr_replace($png, hex2bin(hash('crc32b', substr($png, 12, 17))), 29, 4);
        $this->fakeTransport([
            new ConnectorHttpResult(200, ['content-type' => ['image/png']], $png),
        ]);
        $brand = app(BrandManager::class)->create($this->actor, $this->workspace, 'Oversized Remote Logo Brand');

        Livewire::actingAs($this->actor)
            ->test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->mountAction(TestAction::make('import_logo_from_url')->schemaComponent('brand_media_url_actions'))
            ->set('mountedActions.0.data.url', 'https://example.net/oversized.png')
            ->callMountedAction()
            ->assertHasErrors([
                'mountedActions.0.data.url' => 'Роздільність зображення перевищує 25 МП.',
            ]);

        $this->assertNull($brand->fresh()?->logo_media_asset_id);
        $this->assertSame(0, MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)->count());
    }

    #[Test]
    public function url_response_exceeding_body_limit_has_a_specific_short_20_mib_message(): void
    {
        $this->fakeTransport([
            new ConnectorTransportException(TransportFailureReason::ResponseSizeExceeded),
        ]);

        try {
            app(OriginalImageUrlIngestService::class)->ingest(
                $this->actor,
                $this->workspace,
                'https://images.example.net/excessive.jpg',
            );
            $this->fail('Expected bounded response error.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Розмір файлу перевищує 20 МіБ.'],
                $exception->errors()['url'] ?? null,
            );
        }

        $this->assertSame(0, MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)->count());
    }

    #[Test]
    public function brand_url_action_imports_and_persists_a_managed_logo(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $file = UploadedFile::fake()->image('remote-logo.png', 400, 300);
        $transport = $this->fakeTransport([
            new ConnectorHttpResult(200, ['content-type' => ['image/png']], file_get_contents($file->getRealPath())),
        ]);
        $brand = app(BrandManager::class)->create($this->actor, $this->workspace, 'Imported Logo Brand');

        Livewire::actingAs($this->actor)
            ->test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->mountAction(TestAction::make('import_logo_from_url')->schemaComponent('brand_media_url_actions'))
            ->set('mountedActions.0.data.url', 'https://cdn.example.net/remote-logo.png')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->call('save')
            ->assertHasNoFormErrors();

        $brand->refresh();
        $this->assertNotNull($brand->logo_media_asset_id);
        $asset = MediaAsset::withoutWorkspaceScope()->findOrFail($brand->logo_media_asset_id);
        $this->assertSame($this->workspace->id, $asset->workspace_id);
        $this->assertNull($asset->source_url);
        $this->assertCount(1, $transport->requests);
    }

    #[Test]
    public function product_url_action_ingests_original_and_attaches_gallery_atomically(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $file = UploadedFile::fake()->image('remote-product.png', 400, 300);
        $transport = $this->fakeTransport([
            new ConnectorHttpResult(200, ['content-type' => ['image/png']], file_get_contents($file->getRealPath())),
        ]);
        $product = $this->product();

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction(TestAction::make('add_product_image_url')->schemaComponent('product_media_url_actions'))
            ->set('mountedActions.0.data.url', 'https://cdn.example.net/remote-product.png')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $link = ProductMedia::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('product_id', $product->id)
            ->sole();
        $asset = MediaAsset::withoutWorkspaceScope()->findOrFail($link->media_asset_id);
        $this->assertSame($this->workspace->id, $asset->workspace_id);
        $this->assertNull($asset->parent_media_asset_id);
        $this->assertSame('remote-product.png', $asset->original_filename);
        $this->assertSame(1, MediaAsset::withoutWorkspaceScope()->count());
        $this->assertCount(1, $transport->requests);
        Storage::disk('public')->assertExists((string) $asset->storage_path);
        $this->assertCount(1, $product->fresh()->images);
    }

    #[Test]
    public function product_url_action_rolls_back_new_original_when_legacy_gallery_blocks_association(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $file = UploadedFile::fake()->image('legacy-blocked.png', 400, 300);
        $this->fakeTransport([
            new ConnectorHttpResult(200, ['content-type' => ['image/png']], file_get_contents($file->getRealPath())),
        ]);
        $product = $this->product(['https://legacy.example.net/existing.png']);

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction(TestAction::make('add_product_image_url')->schemaComponent('product_media_url_actions'))
            ->set('mountedActions.0.data.url', 'https://cdn.example.net/legacy-blocked.png')
            ->callMountedAction()
            ->assertHasErrors(['mountedActions.0.data.url']);

        $this->assertSame(0, MediaAsset::withoutWorkspaceScope()->count());
        $this->assertSame(0, ProductMedia::withoutWorkspaceScope()->count());
        $this->assertSame([], Storage::disk('public')->allFiles('media/originals/'.$this->workspace->id));
        $this->assertSame(['https://legacy.example.net/existing.png'], $product->fresh()->images);
    }

    #[Test]
    public function product_url_action_exposes_transport_validation_inline_without_persisting(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $transport = $this->fakeTransport([
            new ConnectorHttpResult(403, ['content-type' => ['text/plain']], 'Request blocked'),
        ]);
        $product = $this->product();

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction(TestAction::make('add_product_image_url')->schemaComponent('product_media_url_actions'))
            ->set('mountedActions.0.data.url', 'https://cdn.example.net/blocked.png')
            ->callMountedAction()
            ->assertHasErrors([
                'mountedActions.0.data.url' => 'Сервер зображення не повернув файл. Перевірте URL.',
            ]);

        $this->assertCount(1, $transport->requests);
        $this->assertSame(0, MediaAsset::withoutWorkspaceScope()->count());
        $this->assertSame(0, ProductMedia::withoutWorkspaceScope()->count());
    }

    private function product(array $images = []): Product
    {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Remote Media Product '.uniqid(),
            'images' => $images,
            'is_active' => true,
        ]);
    }

    /** @param list<ConnectorHttpResult|ConnectorTransportException> $responses */
    private function fakeTransport(array $responses): ConnectorHttpTransport
    {
        $transport = new MediaUrlFakeTransport($responses);

        $this->app->instance(ConnectorHttpTransport::class, $transport);

        return $transport;
    }
}

/** Isolated response queue; no real network calls in URL ingest tests. */
final class MediaUrlFakeTransport implements ConnectorHttpTransport
{
    /** @var list<ConnectorOutboundRequest> */
    public array $requests = [];

    /** @param list<ConnectorHttpResult|ConnectorTransportException> $responses */
    public function __construct(private array $responses) {}

    public function send(ConnectorOutboundRequest $request): ConnectorHttpResult
    {
        $this->requests[] = $request;

        $response = array_shift($this->responses)
            ?? throw new LogicException('Unexpected outbound request.');

        if ($response instanceof ConnectorTransportException) {
            throw $response;
        }

        return $response;
    }
}
