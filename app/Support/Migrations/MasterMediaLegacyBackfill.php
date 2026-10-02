<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MasterMediaLegacyBackfill
{
    public function run(): void
    {
        DB::table('products')
            ->select(['id', 'workspace_id', 'images'])
            ->whereNotNull('images')
            ->orderBy('id')
            ->chunkById(200, function ($products): void {
                foreach ($products as $product) {
                    $workspaceId = (string) ($product->workspace_id ?? '');
                    if ($workspaceId === '' || DB::table('product_media')
                        ->where('workspace_id', $workspaceId)
                        ->where('product_id', $product->id)
                        ->exists()) {
                        continue;
                    }

                    $images = is_string($product->images)
                        ? json_decode($product->images, true)
                        : $product->images;

                    if (! is_array($images) || ! array_is_list($images)) {
                        continue;
                    }

                    $urls = [];
                    $structurallyValid = true;

                    foreach ($images as $index => $value) {
                        if (! is_string($value) || trim($value) === '') {
                            $structurallyValid = false;
                            break;
                        }

                        $urls[(int) $index] = trim($value);
                    }

                    if (! $structurallyValid || $urls === []) {
                        continue;
                    }

                    foreach ($urls as $index => $url) {
                        $assetId = (string) Str::uuid();
                        $now = now();

                        DB::table('media_assets')->insert([
                            'id' => $assetId,
                            'workspace_id' => $workspaceId,
                            'parent_media_asset_id' => null,
                            'asset_type' => 'image',
                            'storage_disk' => null,
                            'storage_path' => null,
                            'source_url' => $url,
                            'original_filename' => null,
                            'mime_type' => null,
                            'byte_size' => null,
                            'content_sha256' => null,
                            'width_px' => null,
                            'height_px' => null,
                            'diagnosis_status' => 'pending',
                            'diagnosis_json' => null,
                            'provenance_json' => json_encode([
                                'kind' => 'legacy_products_images',
                                'legacy_index' => $index,
                            ], JSON_THROW_ON_ERROR),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        DB::table('product_media')->insert([
                            'id' => (string) Str::uuid(),
                            'workspace_id' => $workspaceId,
                            'product_id' => $product->id,
                            'media_asset_id' => $assetId,
                            'role' => $index === 0 ? 'primary' : 'gallery',
                            'sort_order' => $index,
                            'locale' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }, 'id');
    }
}
