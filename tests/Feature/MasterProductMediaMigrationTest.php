<?php

namespace Tests\Feature;

use App\Enums\MediaRole;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Services\Catalog\ProductMediaReadService;
use App\Support\Migrations\MasterMediaLegacyBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class MasterProductMediaMigrationTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    #[Test]
    public function legacy_backfill_is_ordered_idempotent_and_leaves_malformed_products_on_legacy_path(): void
    {
        $workspace = $this->defaultWorkspace();

        $valid = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'LEGACY-MEDIA-VALID',
            'name' => 'Valid legacy media',
            'images' => [
                'https://legacy.example.test/primary.jpg',
                'https://legacy.example.test/gallery.jpg',
            ],
            'is_active' => true,
        ]);

        $malformed = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'LEGACY-MEDIA-MALFORMED',
            'name' => 'Malformed legacy media',
            'images' => [
                'https://legacy.example.test/valid.jpg',
                '',
                123,
            ],
            'is_active' => true,
        ]);

        $backfill = new MasterMediaLegacyBackfill;
        $backfill->run();

        $links = ProductMedia::withoutWorkspaceScope()
            ->where('product_id', $valid->id)
            ->with(['asset' => fn ($query) => $query->withoutGlobalScopes()])
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $links);
        $this->assertSame(MediaRole::Primary, $links[0]->role);
        $this->assertSame(0, $links[0]->sort_order);
        $this->assertSame('https://legacy.example.test/primary.jpg', $links[0]->asset->source_url);
        $this->assertSame(MediaRole::Gallery, $links[1]->role);
        $this->assertSame(1, $links[1]->sort_order);
        $this->assertSame('https://legacy.example.test/gallery.jpg', $links[1]->asset->source_url);

        $this->assertSame(0, ProductMedia::withoutWorkspaceScope()->where('product_id', $malformed->id)->count());
        $this->assertNull(app(ProductMediaReadService::class)->firstClassSourceReferences($malformed));

        $backfill->run();

        $this->assertSame(2, ProductMedia::withoutWorkspaceScope()->where('product_id', $valid->id)->count());
        $this->assertSame([
            'https://legacy.example.test/primary.jpg',
            'https://legacy.example.test/gallery.jpg',
        ], app(ProductMediaReadService::class)->orderedUrls($valid->fresh()));
    }
}
