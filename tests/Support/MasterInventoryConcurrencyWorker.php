<?php

use App\Exceptions\Availability\InsufficientAvailabilityException;
use App\Exceptions\Availability\InventoryMutationException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Availability\MasterInventoryMutationService;
use App\Services\Availability\ReservationConfirmer;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $mode, $workspaceId, $productId, $variantId, $actorId, $subjectId, $value, $ipcDir] = $argv;

$pid = getmypid();
$ready = $ipcDir.'/'.$pid.'.ready';
$result = $ipcDir.'/'.$pid.'.result';

file_put_contents($ready, 'ready');

$deadline = microtime(true) + 60;
while (! is_file($ipcDir.'/go') && microtime(true) < $deadline) {
    usleep(25_000);
}

if (! is_file($ipcDir.'/go')) {
    file_put_contents($result, json_encode(['status' => 'timeout'], JSON_THROW_ON_ERROR));
    exit(2);
}

try {
    if ($mode === 'inventory') {
        $workspace = Workspace::query()->findOrFail($workspaceId);
        $product = Product::withoutWorkspaceScope()->findOrFail((int) $productId);
        $variant = ProductVariant::withoutWorkspaceScope()->findOrFail((int) $variantId);
        $actor = User::query()->findOrFail((int) $actorId);

        app(MasterInventoryMutationService::class)->setQuantity(
            $actor,
            $workspace,
            $product,
            $variant,
            expectedQuantity: (int) $subjectId,
            newQuantity: (int) $value,
            reason: 'Concurrent inventory proof',
        );

        file_put_contents($result, json_encode([
            'status' => 'success',
            'quantity' => (int) $value,
        ], JSON_THROW_ON_ERROR));
        exit(0);
    }

    if ($mode === 'reservation') {
        $reservation = Reservation::query()->findOrFail((int) $subjectId);

        app(ReservationConfirmer::class)->confirm($reservation);

        file_put_contents($result, json_encode([
            'status' => 'confirmed',
            'reservation_id' => (int) $reservation->id,
        ], JSON_THROW_ON_ERROR));
        exit(0);
    }

    throw new RuntimeException('Unknown Master Inventory concurrency worker mode.');
} catch (InventoryMutationException $exception) {
    file_put_contents($result, json_encode([
        'status' => str_contains($exception->getMessage(), 'уже змінився') ? 'stale' : 'inventory_error',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (InsufficientAvailabilityException $exception) {
    file_put_contents($result, json_encode([
        'status' => 'insufficient',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($result, json_encode([
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
