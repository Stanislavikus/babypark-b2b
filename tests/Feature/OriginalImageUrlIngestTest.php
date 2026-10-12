<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\BrandResource\Pages\EditBrand;
use App\Models\MediaAsset;
use App\Services\Catalog\BrandManager;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\OriginalImageUrlIngestService;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
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
    public function invalid_remote_content_is_not_saved(): void
    {
        $this->fakeTransport([new ConnectorHttpResult(200, ['content-type' => ['image/png']], '<html>not an image</html>')]);

        $this->expectException(MediaIngestException::class);
        app(OriginalImageUrlIngestService::class)
            ->ingest($this->actor, $this->workspace, 'https://example.net/not-image.png');
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

    /** @param list<ConnectorHttpResult> $responses */
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

    /** @param list<ConnectorHttpResult> $responses */
    public function __construct(private array $responses) {}

    public function send(ConnectorOutboundRequest $request): ConnectorHttpResult
    {
        $this->requests[] = $request;

        return array_shift($this->responses)
            ?? throw new LogicException('Unexpected outbound request.');
    }
}
