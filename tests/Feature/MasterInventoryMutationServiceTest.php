<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Enums\InventoryRecordSourceType;
use App\Enums\UserRole;
use App\Exceptions\Availability\InventoryMutationException;
use App\Models\InventoryLocation;
use App\Models\InventoryRecord;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Availability\MasterInventoryMutationService;
use App\Services\Availability\MasterInventoryReadService;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class MasterInventoryMutationServiceTest extends TestCase
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
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Inventory manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);
    }

    #[Test]
    public function first_edit_creates_internal_default_stock_and_records_exact_adjustment(): void
    {
        [$product, $variant] = $this->manualProductWithVariant(0);

        $stock = app(MasterInventoryMutationService::class)->setQuantity(
            $this->actor,
            $this->workspace,
            $product,
            $variant,
            expectedQuantity: 0,
            newQuantity: 7,
            reason: '  Initial count  ',
        );

        $location = InventoryLocation::withoutWorkspaceScope()->sole();
        $variant->refresh();

        $this->assertSame('Основна локація', $location->name);
        $this->assertTrue($location->is_default);
        $this->assertTrue($location->is_active);
        $this->assertSame($location->id, $stock->inventory_location_id);
        $this->assertSame(7, $stock->quantity);
        $this->assertSame(7, $variant->available_quantity_cache);
        $this->assertSame(AvailabilityStatus::InStock, $variant->availability_status);

        $record = InventoryRecord::withoutWorkspaceScope()->sole();
        $this->assertSame(InventoryRecordSourceType::ManualAdjustment, $record->source_type);
        $this->assertSame($location->id, $record->inventory_location_id);
        $this->assertSame('Основна локація', $record->location_name_snapshot);
        $this->assertSame(7, $record->quantity_change);
        $this->assertSame(7, $record->resulting_quantity);
        $this->assertSame('Initial count', $record->reason);

        app(MasterInventoryMutationService::class)->setQuantity(
            $this->actor,
            $this->workspace,
            $product,
            $variant->fresh(),
            expectedQuantity: 7,
            newQuantity: 7,
        );

        $this->assertSame(1, InventoryRecord::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function stale_expected_quantity_is_rejected_without_partial_mutation(): void
    {
        [$product, $variant] = $this->manualProductWithVariant(5);
        $stock = $this->stock($variant, 5);

        try {
            app(MasterInventoryMutationService::class)->setQuantity(
                $this->actor,
                $this->workspace,
                $product,
                $variant,
                expectedQuantity: 4,
                newQuantity: 9,
            );

            $this->fail('Expected stale inventory mutation to be rejected.');
        } catch (InventoryMutationException $exception) {
            $this->assertStringContainsString('уже змінився', $exception->getMessage());
        }

        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertSame(5, $variant->fresh()->available_quantity_cache);
        $this->assertSame(0, InventoryRecord::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function stock_cache_mismatch_is_read_only_until_reconciled(): void
    {
        [$product, $variant] = $this->manualProductWithVariant(8);
        $stock = $this->stock($variant, 10);

        $this->expectException(InventoryMutationException::class);
        $this->expectExceptionMessage('потребує звірки');

        try {
            app(MasterInventoryMutationService::class)->setQuantity(
                $this->actor,
                $this->workspace,
                $product,
                $variant,
                expectedQuantity: 10,
                newQuantity: 11,
            );
        } finally {
            $this->assertSame(10, $stock->fresh()->quantity);
            $this->assertSame(8, $variant->fresh()->available_quantity_cache);
            $this->assertSame(0, InventoryRecord::withoutWorkspaceScope()->count());
        }
    }

    #[Test]
    public function multiple_stock_rows_are_read_only_and_never_receive_aggregate_mutation(): void
    {
        [$product, $variant] = $this->manualProductWithVariant(10);
        $this->stock($variant, 4, 'Location A', true);
        $this->stock($variant, 6, 'Location B', false);

        $this->expectException(InventoryMutationException::class);
        $this->expectExceptionMessage('кількох локацій');

        try {
            app(MasterInventoryMutationService::class)->setQuantity(
                $this->actor,
                $this->workspace,
                $product,
                $variant,
                expectedQuantity: 10,
                newQuantity: 12,
            );
        } finally {
            $this->assertSame(
                [4, 6],
                Stock::withoutWorkspaceScope()
                    ->where('variant_id', $variant->id)
                    ->orderBy('quantity')
                    ->pluck('quantity')
                    ->all(),
            );
            $this->assertSame(10, $variant->fresh()->available_quantity_cache);
            $this->assertSame(0, InventoryRecord::withoutWorkspaceScope()->count());
        }
    }

    #[Test]
    public function zero_stock_with_ambiguous_active_locations_fails_closed(): void
    {
        [$product, $variant] = $this->manualProductWithVariant(0);

        foreach (['Location A', 'Location B'] as $name) {
            InventoryLocation::withoutWorkspaceScope()->create([
                'workspace_id' => $this->workspace->id,
                'name' => $name,
                'type' => 'warehouse',
                'is_default' => false,
                'is_active' => true,
            ]);
        }

        $this->expectException(InventoryMutationException::class);
        $this->expectExceptionMessage('основну локацію');

        app(MasterInventoryMutationService::class)->setQuantity(
            $this->actor,
            $this->workspace,
            $product,
            $variant,
            expectedQuantity: 0,
            newQuantity: 3,
        );
    }

    #[Test]
    public function source_owned_product_is_fail_closed_for_inventory_writer(): void
    {
        [$product, $variant] = $this->manualProductWithVariant(0);
        $product->update(['onec_guid' => '33333333-3333-4333-8333-333333333333']);

        $this->expectException(InventoryMutationException::class);
        $this->expectExceptionMessage('1С');

        try {
            app(MasterInventoryMutationService::class)->setQuantity(
                $this->actor,
                $this->workspace,
                $product->fresh(),
                $variant,
                expectedQuantity: 0,
                newQuantity: 4,
            );
        } finally {
            $this->assertSame(0, InventoryLocation::withoutWorkspaceScope()->count());
            $this->assertSame(0, Stock::withoutWorkspaceScope()->count());
            $this->assertSame(0, InventoryRecord::withoutWorkspaceScope()->count());
        }
    }

    #[Test]
    public function source_owned_variant_is_read_only_and_fail_closed_for_inventory_writer(): void
    {
        [$product, $variant] = $this->manualProductWithVariant(3);
        $variant->update(['onec_guid' => '44444444-4444-4444-8444-444444444444']);
        $stock = $this->stock($variant, 3);

        $state = app(MasterInventoryReadService::class)
            ->state($variant->fresh());

        $this->assertFalse($state['editable']);
        $this->assertSame('source_owned_read_only', $state['state']);
        $this->assertSame(3, $state['current_quantity']);

        $this->expectException(InventoryMutationException::class);
        $this->expectExceptionMessage('1С');

        try {
            app(MasterInventoryMutationService::class)->setQuantity(
                $this->actor,
                $this->workspace,
                $product,
                $variant->fresh(),
                expectedQuantity: 3,
                newQuantity: 5,
            );
        } finally {
            $this->assertSame(3, $stock->fresh()->quantity);
            $this->assertSame(3, $variant->fresh()->available_quantity_cache);
            $this->assertSame(0, InventoryRecord::withoutWorkspaceScope()->count());
        }
    }

    #[Test]
    public function mutation_requires_manage_products_permission(): void
    {
        [$product, $variant] = $this->manualProductWithVariant(0);
        $other = User::factory()->create();
        $this->makeWorkspaceMembership($this->workspace, $other);

        $this->expectException(AuthorizationException::class);

        app(MasterInventoryMutationService::class)->setQuantity(
            $other,
            $this->workspace,
            $product,
            $variant,
            expectedQuantity: 0,
            newQuantity: 1,
        );
    }

    #[Test]
    public function zero_stock_with_positive_cache_requires_reconciliation_and_does_not_create_location(): void
    {
        [$product, $variant] = $this->manualProductWithVariant(4);

        $this->expectException(InventoryMutationException::class);
        $this->expectExceptionMessage('потребує звірки');

        try {
            app(MasterInventoryMutationService::class)->setQuantity(
                $this->actor,
                $this->workspace,
                $product,
                $variant,
                expectedQuantity: 4,
                newQuantity: 5,
            );
        } finally {
            $this->assertSame(0, InventoryLocation::withoutWorkspaceScope()->count());
            $this->assertSame(0, Stock::withoutWorkspaceScope()->count());
        }
    }

    /**
     * @return array{0: Product, 1: ProductVariant}
     */
    private function manualProductWithVariant(int $cache): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'name' => 'Manual inventory product',
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => null,
            'attributes' => [],
            'is_active' => true,
            'available_quantity_cache' => $cache,
            'availability_status' => $cache > 0 ? 'in_stock' : 'out_of_stock',
        ]);

        return [$product, $variant];
    }

    private function stock(
        ProductVariant $variant,
        int $quantity,
        string $locationName = 'Main',
        bool $default = true,
    ): Stock {
        $location = InventoryLocation::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => $locationName,
            'type' => 'warehouse',
            'is_default' => $default,
            'is_active' => true,
        ]);

        return Stock::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'variant_id' => $variant->id,
            'inventory_location_id' => $location->id,
            'quantity' => $quantity,
            'expected_date' => null,
            'expected_quantity' => null,
        ]);
    }
}
