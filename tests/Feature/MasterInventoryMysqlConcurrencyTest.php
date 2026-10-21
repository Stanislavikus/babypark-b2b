<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\InventoryLocation;
use App\Models\InventoryRecord;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Models\Stock;
use App\Models\User;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class MasterInventoryMysqlConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use InteractsWithWorkspaceRbac;

    #[Test]
    public function mysql_inventory_mutation_and_reservation_confirmation_are_race_safe(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Master Inventory concurrency proof requires MySQL.');
        }

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions($workspace->id, 'Inventory concurrency manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Inventory concurrency product',
            'is_active' => true,
        ]);

        $inventoryVariant = $this->variant($product, 'INV-CONC-EDIT', 0);
        $reservationVariant = $this->variant($product, 'INV-CONC-RESERVE', 10);

        $customer = Customer::query()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'name' => 'Inventory concurrency customer',
            'short_name' => 'ICC',
            'login' => 'inventory-concurrency-'.Str::random(6),
            'password' => 'password',
            'is_active' => true,
        ]);

        try {
            $editResults = $this->runWorkers([
                ['inventory', (string) $workspace->id, (string) $product->id, (string) $inventoryVariant->id, (string) $actor->id, '0', '5'],
                ['inventory', (string) $workspace->id, (string) $product->id, (string) $inventoryVariant->id, (string) $actor->id, '0', '7'],
            ]);

            $this->assertSame(
                ['stale', 'success'],
                collect($editResults)->pluck('status')->sort()->values()->all(),
            );

            $winnerQuantity = (int) collect($editResults)->firstWhere('status', 'success')['quantity'];
            $inventoryVariant->refresh();
            $editStock = Stock::withoutWorkspaceScope()
                ->where('variant_id', $inventoryVariant->id)
                ->sole();

            $this->assertSame($winnerQuantity, $editStock->quantity);
            $this->assertSame($winnerQuantity, $inventoryVariant->available_quantity_cache);
            $this->assertSame(
                1,
                InventoryRecord::withoutWorkspaceScope()
                    ->where('product_variant_id', $inventoryVariant->id)
                    ->count(),
            );
            $this->assertSame(
                1,
                InventoryLocation::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->where('name', 'Основна локація')
                    ->count(),
            );

            $reservationLocation = InventoryLocation::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'name' => 'Reservation concurrency location',
                'type' => 'warehouse',
                'is_default' => false,
                'is_active' => true,
            ]);
            Stock::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'variant_id' => $reservationVariant->id,
                'inventory_location_id' => $reservationLocation->id,
                'quantity' => 10,
                'expected_date' => null,
                'expected_quantity' => null,
            ]);

            $reservations = collect([1, 2])->map(fn (): Reservation => Reservation::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'order_id' => null,
                'variant_id' => $reservationVariant->id,
                'quantity' => 6,
                'status' => ReservationStatus::Pending,
                'expires_at' => now()->addHour(),
            ]));

            $reservationResults = $this->runWorkers(
                $reservations
                    ->map(fn (Reservation $reservation): array => [
                        'reservation',
                        (string) $workspace->id,
                        (string) $product->id,
                        (string) $reservationVariant->id,
                        (string) $actor->id,
                        (string) $reservation->id,
                        '0',
                    ])
                    ->all(),
            );

            $this->assertSame(
                ['confirmed', 'insufficient'],
                collect($reservationResults)->pluck('status')->sort()->values()->all(),
            );

            $reservationVariant->refresh();
            $reservationStock = Stock::withoutWorkspaceScope()
                ->where('variant_id', $reservationVariant->id)
                ->sole();

            $this->assertSame(4, $reservationVariant->available_quantity_cache);
            $this->assertSame(4, $reservationStock->quantity);
            $this->assertSame(
                [ReservationStatus::Confirmed->value, ReservationStatus::Pending->value],
                Reservation::withoutWorkspaceScope()
                    ->whereIn('id', $reservations->pluck('id'))
                    ->pluck('status')
                    ->map(fn ($status): string => $status instanceof ReservationStatus ? $status->value : (string) $status)
                    ->sort()
                    ->values()
                    ->all(),
            );
            $this->assertSame(
                1,
                InventoryRecord::withoutWorkspaceScope()
                    ->where('product_variant_id', $reservationVariant->id)
                    ->count(),
            );
        } finally {
            $this->cleanupCommittedFixtures(
                workspaceId: (string) $workspace->id,
                productId: (int) $product->id,
                actorId: (int) $actor->id,
                membershipId: (string) $membership->id,
                roleId: (string) $role->id,
                customerId: (int) $customer->id,
                locationIds: InventoryLocation::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('name', ['Основна локація', 'Reservation concurrency location'])
                    ->pluck('id')
                    ->map(fn ($id): string => (string) $id)
                    ->all(),
            );
        }
    }

    /**
     * @param  list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string}>  $specs
     * @return list<array<string,mixed>>
     */
    private function runWorkers(array $specs): array
    {
        $ipcDir = sys_get_temp_dir().'/master-inventory-concurrency-'.uniqid('', true);
        File::ensureDirectoryExists($ipcDir);

        $worker = base_path('tests/Support/MasterInventoryConcurrencyWorker.php');
        $processes = [];

        try {
            foreach ($specs as $spec) {
                $process = new Process([
                    PHP_BINARY,
                    $worker,
                    ...$spec,
                    $ipcDir,
                ], base_path());

                $process->setTimeout(90);
                $process->start();

                $processes[] = [
                    'pid' => $process->getPid(),
                    'process' => $process,
                ];
            }

            $this->waitForReadyWorkers($ipcDir, count($processes));
            file_put_contents($ipcDir.'/go', '1');

            $results = [];
            foreach ($processes as $entry) {
                /** @var Process $process */
                $process = $entry['process'];
                $process->wait();

                $this->assertSame(
                    0,
                    $process->getExitCode(),
                    $process->getErrorOutput().$process->getOutput(),
                );

                $path = $ipcDir.'/'.$entry['pid'].'.result';
                $this->assertFileExists($path);
                $results[] = json_decode(
                    (string) file_get_contents($path),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            }

            return $results;
        } finally {
            foreach ($processes as $entry) {
                /** @var Process $process */
                $process = $entry['process'];
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            File::deleteDirectory($ipcDir);
        }
    }

    private function waitForReadyWorkers(string $ipcDir, int $expected): void
    {
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            if (count(glob($ipcDir.'/*.ready') ?: []) === $expected) {
                return;
            }

            usleep(25_000);
        }

        $this->fail('Timed out waiting for Master Inventory concurrency workers.');
    }

    private function variant(Product $product, string $sku, int $cache): ProductVariant
    {
        return ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $product->workspace_id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => $sku,
            'attributes' => [],
            'is_active' => true,
            'available_quantity_cache' => $cache,
            'availability_status' => $cache > 0 ? 'in_stock' : 'out_of_stock',
        ]);
    }

    /**
     * @param  list<string>  $locationIds
     */
    private function cleanupCommittedFixtures(
        string $workspaceId,
        int $productId,
        int $actorId,
        string $membershipId,
        string $roleId,
        int $customerId,
        array $locationIds,
    ): void {
        DB::transaction(function () use (
            $workspaceId,
            $productId,
            $actorId,
            $membershipId,
            $roleId,
            $customerId,
            $locationIds,
        ): void {
            DB::table('products')
                ->where('workspace_id', $workspaceId)
                ->where('id', $productId)
                ->delete();

            DB::table('customers')
                ->where('workspace_id', $workspaceId)
                ->where('id', $customerId)
                ->delete();

            DB::table('inventory_locations')
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $locationIds)
                ->delete();

            DB::table('workspace_user_roles')
                ->where('workspace_id', $workspaceId)
                ->where('workspace_user_id', $membershipId)
                ->where('workspace_role_id', $roleId)
                ->delete();

            DB::table('workspace_role_permissions')
                ->where('workspace_id', $workspaceId)
                ->where('workspace_role_id', $roleId)
                ->delete();

            DB::table('workspace_roles')
                ->where('workspace_id', $workspaceId)
                ->where('id', $roleId)
                ->delete();

            DB::table('workspace_users')
                ->where('workspace_id', $workspaceId)
                ->where('id', $membershipId)
                ->delete();

            DB::table('users')
                ->where('id', $actorId)
                ->delete();
        });
    }
}
