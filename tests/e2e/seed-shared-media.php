<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Services\Catalog\BrandManager;
use App\Services\Media\OriginalImageIngestService;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
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
    throw new RuntimeException('E2E fixture provisioning is restricted to the isolated CI MySQL database.');
}

$workspace = Workspace::query()->where('is_default', true)->sole();
$actor = User::query()->where('email', 'admin@babypark.ua')->sole();
$membership = WorkspaceUser::query()->firstOrCreate([
    'workspace_id' => $workspace->id,
    'user_id' => $actor->id,
], ['is_active' => true]);
$role = WorkspaceRole::query()->create([
    'workspace_id' => $workspace->id,
    'name' => 'Synthetic E2E Media Manager',
]);
$permission = WorkspacePermission::query()->where('code', WorkspacePermissions::MANAGE_PRODUCTS)->sole();
DB::table('workspace_role_permissions')->insert([
    'workspace_id' => $workspace->id,
    'workspace_role_id' => $role->id,
    'workspace_permission_id' => $permission->id,
]);
DB::table('workspace_user_roles')->insert([
    'workspace_id' => $workspace->id,
    'workspace_user_id' => $membership->id,
    'workspace_role_id' => $role->id,
]);

$dir = dirname(__DIR__, 2).'/test-results/browser-smoke';
if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
    throw new RuntimeException('Cannot create isolated fixture directory.');
}

$render = static function (string $name, int $red, int $green, int $blue, int $width = 600, int $height = 420) use ($dir): string {
    $path = $dir.'/'.$name;
    $canvas = imagecreatetruecolor($width, $height);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, $red, $green, $blue));
    imagepng($canvas, $path);
    imagedestroy($canvas);

    return $path;
};

$sharedPath = $render('shared.png', 28, 96, 178);
$otherPath = $render('other.png', 34, 156, 91);
$replacementPath = $render('replacement.png', 188, 63, 48);
$render('square.png', 196, 112, 38, 480, 480);
$render('portrait.png', 56, 112, 188, 420, 640);
$upload = static fn (string $path): UploadedFile => new UploadedFile($path, basename($path), 'image/png', null, true);
$images = app(OriginalImageIngestService::class);
$shared = $images->ingestStandalone($actor, $workspace, $upload($sharedPath));
$other = $images->ingestStandalone($actor, $workspace, $upload($otherPath));
$brands = app(BrandManager::class);
$first = $brands->create($actor, $workspace, 'E2E Shared Brand One', logoMediaAssetId: $shared->id);
$second = $brands->create($actor, $workspace, 'E2E Shared Brand Two', logoMediaAssetId: $shared->id);
$third = $brands->create($actor, $workspace, 'E2E Different Brand', logoMediaAssetId: $other->id);
$pickerBrand = $brands->create($actor, $workspace, 'E2E Visual Picker Brand');

file_put_contents($dir.'/fixture.json', json_encode([
    'sharedAssetId' => $shared->id,
    'sharedHash' => $shared->content_sha256,
    'firstBrandId' => $first->id,
    'secondBrandId' => $second->id,
    'differentBrandId' => $third->id,
    'pickerBrandId' => $pickerBrand->id,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

echo "Isolated shared-media fixture created.\n";
