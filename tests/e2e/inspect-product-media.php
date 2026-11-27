<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (
    ! $app->environment('e2e')
    || getenv('CI') !== 'true'
    || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'babypark_e2e'
    || config('database.connections.mysql.host') !== '127.0.0.1'
) {
    throw new RuntimeException('Product media inspection is restricted to the isolated CI MySQL database.');
}

$productId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
if ($productId === false || $productId < 1) {
    throw new InvalidArgumentException('A numeric E2E product ID is required.');
}

$product = DB::table('products')->where('id', $productId)->first(['id', 'workspace_id']);
if ($product === null) {
    throw new RuntimeException('E2E product not found.');
}

$links = DB::table('product_media')
    ->where('workspace_id', $product->workspace_id)
    ->where('product_id', $productId)
    ->whereNull('locale')
    ->orderBy('sort_order')
    ->get(['media_asset_id', 'role', 'sort_order'])
    ->map(fn ($item): array => [
        'media_asset_id' => (string) $item->media_asset_id,
        'role' => (string) $item->role,
        'sort_order' => (int) $item->sort_order,
    ])
    ->all();

echo json_encode([
    'assetCount' => DB::table('media_assets')
        ->where('workspace_id', $product->workspace_id)
        ->count(),
    'links' => $links,
], JSON_THROW_ON_ERROR);
