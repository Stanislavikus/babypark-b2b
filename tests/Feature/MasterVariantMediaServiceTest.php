<?php

namespace Tests\Feature;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Enums\MediaRole;
use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantMedia;
use App\Models\Workspace;
use App\Services\Catalog\VariantMediaMutationService;
use App\Services\Catalog\VariantMediaReadService;
use App\Support\Catalog\Exceptions\VariantMediaException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class MasterVariantMediaServiceTest extends TestCase
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
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Variant media manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);
    }

    #[Test]
    public function bulk_assignment_to_twenty_variants_allocates_independent_order_and_is_idempotent(): void
    {
        [$product, $variants] = $this->productWithVariants(20);
        $first = $this->asset('bulk-1.jpg');
        $second = $this->asset('bulk-2.jpg');
        $variantIds = $variants->pluck('id')->all();

        $service = app(VariantMediaMutationService::class);
        $service->assign($this->actor, $this->workspace, $product, $variantIds, [$first->id, $second->id]);

        $beforeIds = VariantMedia::withoutWorkspaceScope()
            ->whereIn('variant_id', $variantIds)
            ->orderBy('variant_id')
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();

        $this->assertCount(40, $beforeIds);

        foreach ($variants as $variant) {
            $rows = VariantMedia::withoutWorkspaceScope()
                ->where('variant_id', $variant->id)
                ->orderBy('sort_order')
                ->get();

            $this->assertSame([0, 1], $rows->pluck('sort_order')->all());
            $this->assertSame(
                [MediaRole::Gallery, MediaRole::Gallery],
                $rows->pluck('role')->all(),
            );
        }

        $service->assign($this->actor, $this->workspace, $product, $variantIds, [$first->id, $second->id]);

        $afterIds = VariantMedia::withoutWorkspaceScope()
            ->whereIn('variant_id', $variantIds)
            ->orderBy('variant_id')
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();

        $this->assertSame($beforeIds, $afterIds);
        $this->assertCount(40, $afterIds);
    }

    #[Test]
    public function explicit_primary_is_only_created_by_explicit_intent(): void
    {
        [$product, $variants] = $this->productWithVariants(1);
        $variant = $variants->sole();
        $first = $this->asset('primary-1.jpg');
        $second = $this->asset('primary-2.jpg');

        $service = app(VariantMediaMutationService::class);
        $service->assign($this->actor, $this->workspace, $product, [$variant->id], [$first->id, $second->id]);

        $this->assertFalse(
            VariantMedia::withoutWorkspaceScope()
                ->where('variant_id', $variant->id)
                ->where('role', MediaRole::Primary->value)
                ->exists(),
        );

        $secondRow = VariantMedia::withoutWorkspaceScope()
            ->where('variant_id', $variant->id)
            ->where('media_asset_id', $second->id)
            ->sole();

        $rows = $service->makePrimary($this->actor, $this->workspace, $product, $variant, (string) $secondRow->id);

        $this->assertSame($second->id, $rows->first()->media_asset_id);
        $this->assertSame(MediaRole::Primary, $rows->first()->role);
        $this->assertSame(0, $rows->first()->sort_order);
    }

    #[Test]
    public function detaching_primary_does_not_auto_promote_remaining_media(): void
    {
        [$product, $variants] = $this->productWithVariants(1);
        $variant = $variants->sole();
        $assets = collect([
            $this->asset('detach-1.jpg'),
            $this->asset('detach-2.jpg'),
            $this->asset('detach-3.jpg'),
        ]);

        $service = app(VariantMediaMutationService::class);
        $service->assign(
            $this->actor,
            $this->workspace,
            $product,
            [$variant->id],
            $assets->pluck('id')->all(),
            makeFirstSelectedPrimary: true,
        );

        $rows = $service->detach(
            $this->actor,
            $this->workspace,
            $product,
            [$variant->id],
            [(string) $assets->first()->id],
        );

        $this->assertSame([0, 1], $rows->pluck('sort_order')->all());
        $this->assertSame(
            [MediaRole::Gallery, MediaRole::Gallery],
            $rows->pluck('role')->all(),
        );
        $this->assertFalse($rows->contains(fn (VariantMedia $row): bool => $row->role === MediaRole::Primary));

        $coverage = app(VariantMediaReadService::class)->coverage($variant);
        $this->assertSame('specific_without_primary', $coverage['state']);
        $this->assertFalse($coverage['has_explicit_primary']);
    }

    #[Test]
    public function reorder_can_move_last_to_first_without_transient_unique_collision_when_no_primary_exists(): void
    {
        [$product, $variants] = $this->productWithVariants(1);
        $variant = $variants->sole();
        $assets = collect([
            $this->asset('reorder-1.jpg'),
            $this->asset('reorder-2.jpg'),
            $this->asset('reorder-3.jpg'),
            $this->asset('reorder-4.jpg'),
        ]);

        $service = app(VariantMediaMutationService::class);
        $service->assign($this->actor, $this->workspace, $product, [$variant->id], $assets->pluck('id')->all());

        $before = VariantMedia::withoutWorkspaceScope()
            ->where('variant_id', $variant->id)
            ->orderBy('sort_order')
            ->get();

        $requested = $before->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $last = array_pop($requested);
        array_unshift($requested, $last);

        $after = $service->reorder($this->actor, $this->workspace, $product, $variant, $requested);

        $this->assertSame($last, (string) $after->first()->id);
        $this->assertSame([0, 1, 2, 3], $after->pluck('sort_order')->all());
        $this->assertFalse($after->contains(fn (VariantMedia $row): bool => $row->role === MediaRole::Primary));
    }

    #[Test]
    public function replace_preserves_retained_association_identity_and_does_not_invent_primary(): void
    {
        [$product, $variants] = $this->productWithVariants(1);
        $variant = $variants->sole();
        $first = $this->asset('replace-1.jpg');
        $second = $this->asset('replace-2.jpg');
        $third = $this->asset('replace-3.jpg');

        $service = app(VariantMediaMutationService::class);
        $service->assign($this->actor, $this->workspace, $product, [$variant->id], [$first->id, $second->id]);

        $retained = VariantMedia::withoutWorkspaceScope()
            ->where('variant_id', $variant->id)
            ->where('media_asset_id', $second->id)
            ->sole();

        $rows = $service->replace(
            $this->actor,
            $this->workspace,
            $product,
            [$variant->id],
            [$second->id, $third->id],
        );

        $this->assertSame([$second->id, $third->id], $rows->pluck('media_asset_id')->all());
        $this->assertSame((string) $retained->id, (string) $rows->first()->id);
        $this->assertFalse($rows->contains(fn (VariantMedia $row): bool => $row->role === MediaRole::Primary));
    }

    #[Test]
    public function composed_presentation_is_specific_then_common_with_asset_dedupe(): void
    {
        [$product, $variants] = $this->productWithVariants(1);
        $variant = $variants->sole();
        $shared = $this->asset('shared.jpg');
        $specific = $this->asset('specific.jpg');
        $common = $this->asset('common.jpg');

        ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $shared->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
            'locale' => null,
        ]);
        ProductMedia::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'media_asset_id' => $common->id,
            'role' => MediaRole::Gallery,
            'sort_order' => 1,
            'locale' => null,
        ]);

        app(VariantMediaMutationService::class)->assign(
            $this->actor,
            $this->workspace,
            $product,
            [$variant->id],
            [$shared->id, $specific->id],
            makeFirstSelectedPrimary: true,
        );

        $ids = app(VariantMediaReadService::class)
            ->composedAssets($variant)
            ->pluck('id')
            ->all();

        $this->assertSame([$shared->id, $specific->id, $common->id], $ids);
    }

    #[Test]
    public function derivative_asset_assignment_fails_closed(): void
    {
        [$product, $variants] = $this->productWithVariants(1);
        $variant = $variants->sole();
        $original = $this->asset('original.jpg');
        $derivative = MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'parent_media_asset_id' => $original->id,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/derivative.jpg',
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);

        $this->expectException(VariantMediaException::class);

        app(VariantMediaMutationService::class)->assign(
            $this->actor,
            $this->workspace,
            $product,
            [$variant->id],
            [$derivative->id],
        );
    }

    /**
     * @return array{0:Product,1:Collection<int,ProductVariant>}
     */
    private function productWithVariants(int $count): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Variant media service product',
            'is_active' => true,
        ]);

        $variants = collect();
        for ($i = 1; $i <= $count; $i++) {
            $variants->push(ProductVariant::withoutWorkspaceScope()->create([
                'workspace_id' => $this->workspace->id,
                'product_id' => $product->id,
                'onec_guid' => null,
                'sku' => sprintf('VM-%03d', $i),
                'attributes' => [],
                'is_active' => true,
            ]));
        }

        return [$product, $variants];
    }

    private function asset(string $filename): MediaAsset
    {
        return MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'parent_media_asset_id' => null,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/'.$filename,
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
    }
}
