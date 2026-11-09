<?php

namespace Tests\Feature;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Enums\MediaRole;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Migrations\MasterMediaAssociationUniquenessPreflight;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class MasterVariantMediaAssociationInvariantTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    #[Test]
    public function common_variant_asset_association_is_unique(): void
    {
        $this->assertTrue(DB::table('migrations')->where('migration', '2026_10_03_190000_enforce_media_association_uniqueness')->exists());
        $indexes = collect(Schema::getIndexes('variant_media'))->pluck('name')->all();
        $this->assertContains('variant_media_owner_asset_locale_unique', $indexes);

        [$product, $variant, $asset] = $this->context();

        $this->insertVariantMedia($variant->id, $asset->id, null, 0);

        $this->expectException(QueryException::class);

        $this->insertVariantMedia($variant->id, $asset->id, null, 1);
    }

    #[Test]
    public function locale_scope_normalizes_case_and_separator(): void
    {
        [, $variant, $asset] = $this->context();

        $this->insertVariantMedia($variant->id, $asset->id, 'de-DE', 0);

        try {
            $this->insertVariantMedia($variant->id, $asset->id, 'de_de', 1);
            $this->fail('Expected normalized locale duplicate to be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseCount('variant_media', 1);
        }
    }

    #[Test]
    public function common_and_locale_specific_associations_can_reuse_the_same_asset(): void
    {
        [, $variant, $asset] = $this->context();

        $this->insertVariantMedia($variant->id, $asset->id, null, 0);
        $this->insertVariantMedia($variant->id, $asset->id, 'de-DE', 1);

        $this->assertDatabaseCount('variant_media', 2);
    }

    #[Test]
    public function same_asset_can_be_used_by_product_and_variant_associations(): void
    {
        [$product, $variant, $asset] = $this->context();

        DB::table('product_media')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $product->workspace_id,
            'product_id' => $product->id,
            'media_asset_id' => $asset->id,
            'role' => MediaRole::Primary->value,
            'sort_order' => 0,
            'locale' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertVariantMedia($variant->id, $asset->id, null, 0);

        $this->assertDatabaseCount('product_media', 1);
        $this->assertDatabaseCount('variant_media', 1);
    }

    #[Test]
    public function same_asset_can_be_used_by_different_variants(): void
    {
        [$product, $variant, $asset] = $this->context();
        $second = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $product->workspace_id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => 'MEDIA-VARIANT-002',
            'attributes' => [],
            'is_active' => true,
        ]);

        $this->insertVariantMedia($variant->id, $asset->id, null, 0);
        $this->insertVariantMedia($second->id, $asset->id, null, 0);

        $this->assertDatabaseCount('variant_media', 2);
    }

    #[Test]
    public function duplicate_preflight_fails_closed_without_repairing_rows(): void
    {
        Schema::create('media_preflight_fixture', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->unsignedBigInteger('owner_id');
            $table->uuid('media_asset_id');
            $table->string('locale', 32)->nullable();
        });

        try {
            $workspaceId = (string) Str::uuid();
            $assetId = (string) Str::uuid();

            DB::table('media_preflight_fixture')->insert([
                [
                    'workspace_id' => $workspaceId,
                    'owner_id' => 10,
                    'media_asset_id' => $assetId,
                    'locale' => 'de-DE',
                ],
                [
                    'workspace_id' => $workspaceId,
                    'owner_id' => 10,
                    'media_asset_id' => $assetId,
                    'locale' => 'de_de',
                ],
            ]);

            try {
                (new MasterMediaAssociationUniquenessPreflight)
                    ->assertNoDuplicates('media_preflight_fixture', 'owner_id');

                $this->fail('Expected duplicate preflight to fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('duplicate owner/asset/locale rows exist', $exception->getMessage());
                $this->assertSame(2, DB::table('media_preflight_fixture')->count());
            }
        } finally {
            Schema::dropIfExists('media_preflight_fixture');
        }
    }

    /**
     * @return array{0:Product,1:ProductVariant,2:MediaAsset}
     */
    private function context(): array
    {
        $workspace = $this->defaultWorkspace();

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'sku' => 'MEDIA-PRODUCT-001',
            'name' => 'Variant media product',
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => 'MEDIA-VARIANT-001',
            'attributes' => [],
            'is_active' => true,
        ]);

        $asset = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'parent_media_asset_id' => null,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/variant-media.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        return [$product, $variant, $asset];
    }

    private function insertVariantMedia(int $variantId, string $assetId, ?string $locale, int $sortOrder): void
    {
        $variant = ProductVariant::withoutWorkspaceScope()->findOrFail($variantId);

        DB::table('variant_media')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $variant->workspace_id,
            'variant_id' => $variant->id,
            'media_asset_id' => $assetId,
            'role' => $sortOrder === 0 ? MediaRole::Primary->value : MediaRole::Gallery->value,
            'sort_order' => $sortOrder,
            'locale' => $locale,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
