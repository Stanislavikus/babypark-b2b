<?php

namespace Tests\Unit;

use App\Enums\ReservationStatus;
use App\Exceptions\Availability\InsufficientAvailabilityException;
use App\Exceptions\Availability\InvalidReservationQuantityException;
use App\Exceptions\Availability\InvalidReservationTransitionException;
use App\Models\Customer;
use App\Models\InventoryRecord;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Services\Availability\AvailabilityResolver;
use App\Services\Availability\ReservationConfirmer;
use App\Services\Availability\ReservationCreator;
use App\Services\Availability\ReservationReleaser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAvailabilityFixtures;
use Tests\TestCase;

class AvailabilityServicesTest extends TestCase
{
    use CreatesAvailabilityFixtures;
    use RefreshDatabase;

    private function createCustomer(): Customer
    {
        return Customer::query()->create([
            'onec_guid' => (string) Str::uuid(),
            'name' => 'Test Customer',
            'short_name' => 'TC',
            'login' => 'svc-'.Str::random(6),
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    private function createVariantWithCache(int $cache): ProductVariant
    {
        $product = Product::create([
            'workspace_id' => $this->defaultWorkspace()->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-'.Str::random(6),
            'name' => 'Svc Product',
            'is_active' => true,
        ]);

        return ProductVariant::create([
            'workspace_id' => $this->defaultWorkspace()->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'VAR-'.Str::random(6),
            'is_active' => true,
            'available_quantity_cache' => $cache,
            'availability_status' => $cache > 0 ? 'in_stock' : 'out_of_stock',
        ]);
    }

    public function test_reservation_creator_honors_ttl_override(): void
    {
        config(['availability.reservation_ttl_minutes' => 15]);

        $variant = $this->createVariantWithCache(50);
        $before = now();

        $reservation = app(ReservationCreator::class)->create(
            $variant,
            2,
            customer: $this->createCustomer(),
            ttlMinutes: 120,
        );

        $this->assertTrue($reservation->expires_at->between(
            $before->copy()->addMinutes(118),
            $before->copy()->addMinutes(122),
        ));
    }

    public function test_reservation_creator_sets_expires_at(): void
    {
        config(['availability.reservation_ttl_minutes' => 20]);

        $variant = $this->createVariantWithCache(50);
        $before = now();
        $after = now();

        $reservation = app(ReservationCreator::class)->create($variant, 3, customer: $this->createCustomer());

        $this->assertNotNull($reservation->expires_at);
        $this->assertTrue($reservation->expires_at->between($before->copy()->addMinutes(19), $after->copy()->addMinutes(21)));
        $this->assertSame(ReservationStatus::Pending, $reservation->status);
    }

    public function test_reservation_creator_rejects_non_positive_quantity(): void
    {
        $variant = $this->createVariantWithCache(10);

        $this->expectException(InvalidReservationQuantityException::class);

        app(ReservationCreator::class)->create($variant, 0);
    }

    public function test_reservation_creator_rejects_insufficient_availability(): void
    {
        $variant = $this->createVariantWithCache(2);

        Reservation::create([
            'workspace_id' => $variant->workspace_id,
            'customer_id' => $this->createCustomer()->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->addHour(),
        ]);

        $this->expectException(InsufficientAvailabilityException::class);

        app(ReservationCreator::class)->create($variant, 1, customer: $this->createCustomer());
    }

    public function test_reservation_confirmer_is_idempotent(): void
    {
        $variant = $this->createVariantWithCache(10);

        $reservation = Reservation::create([
            'workspace_id' => $variant->workspace_id,
            'customer_id' => $this->createCustomer()->id,
            'variant_id' => $variant->id,
            'quantity' => 4,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->addHour(),
        ]);

        $confirmer = app(ReservationConfirmer::class);
        $confirmer->confirm($reservation);
        $confirmer->confirm($reservation->fresh());

        $variant->refresh();

        $this->assertSame(6, $variant->available_quantity_cache);
        $this->assertSame(1, InventoryRecord::query()->where('product_variant_id', $variant->id)->count());
    }

    public function test_reservation_confirmer_rejects_expired_reservation(): void
    {
        $variant = $this->createVariantWithCache(10);

        $reservation = Reservation::create([
            'workspace_id' => $variant->workspace_id,
            'customer_id' => $this->createCustomer()->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'status' => ReservationStatus::Expired,
            'expires_at' => now()->subHour(),
        ]);

        $this->expectException(InvalidReservationTransitionException::class);

        app(ReservationConfirmer::class)->confirm($reservation);
    }

    public function test_reservation_confirmer_rejects_pending_reservation_that_expired_by_time(): void
    {
        $variant = $this->createVariantWithCache(10);

        $reservation = Reservation::create([
            'workspace_id' => $variant->workspace_id,
            'customer_id' => $this->createCustomer()->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->subMinute(),
        ]);

        try {
            app(ReservationConfirmer::class)->confirm($reservation);
            $this->fail('Expected time-expired pending reservation to be rejected.');
        } catch (InvalidReservationTransitionException $exception) {
            $this->assertStringContainsString('expired reservation', $exception->getMessage());
        }

        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status);
        $this->assertSame(10, $variant->fresh()->available_quantity_cache);
        $this->assertSame(0, InventoryRecord::query()->where('product_variant_id', $variant->id)->count());
    }

    public function test_reservation_confirmer_rejects_insufficient_balance_without_truncation(): void
    {
        $variant = $this->createVariantWithCache(1);

        $reservation = Reservation::create([
            'workspace_id' => $variant->workspace_id,
            'customer_id' => $this->createCustomer()->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->addHour(),
        ]);

        try {
            app(ReservationConfirmer::class)->confirm($reservation);
            $this->fail('Expected insufficient allocatable balance to be rejected.');
        } catch (InsufficientAvailabilityException $exception) {
            $this->assertStringContainsString('available 1', $exception->getMessage());
        }

        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status);
        $this->assertSame(1, $variant->fresh()->available_quantity_cache);
        $this->assertSame(0, InventoryRecord::query()->where('product_variant_id', $variant->id)->count());
    }

    public function test_reservation_confirmer_updates_single_stock_cache_and_ledger_atomically(): void
    {
        $product = $this->createProductWithStocks([
            ['name' => 'Main', 'quantity' => 10],
        ]);
        $variant = $product->variants->sole();
        $stock = $variant->stocks->sole();

        $reservation = Reservation::create([
            'workspace_id' => $variant->workspace_id,
            'customer_id' => $this->createCustomer()->id,
            'variant_id' => $variant->id,
            'quantity' => 4,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->addHour(),
        ]);

        app(ReservationConfirmer::class)->confirm($reservation);

        $record = InventoryRecord::query()
            ->where('product_variant_id', $variant->id)
            ->sole();

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame(6, $stock->fresh()->quantity);
        $this->assertSame(6, $variant->fresh()->available_quantity_cache);
        $this->assertSame(-4, $record->quantity_change);
        $this->assertSame(6, $record->resulting_quantity);
        $this->assertSame($stock->inventory_location_id, $record->inventory_location_id);
        $this->assertSame('Main', $record->location_name_snapshot);
    }

    public function test_reservation_confirmer_keeps_mismatched_single_stock_on_compatibility_path(): void
    {
        $product = $this->createProductWithStocks([
            ['name' => 'Legacy', 'quantity' => 12],
        ], availableCache: 10);
        $variant = $product->variants->sole();
        $stock = $variant->stocks->sole();

        $reservation = Reservation::create([
            'workspace_id' => $variant->workspace_id,
            'customer_id' => $this->createCustomer()->id,
            'variant_id' => $variant->id,
            'quantity' => 3,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->addHour(),
        ]);

        app(ReservationConfirmer::class)->confirm($reservation);

        $record = InventoryRecord::query()
            ->where('product_variant_id', $variant->id)
            ->sole();

        $this->assertSame(12, $stock->fresh()->quantity);
        $this->assertSame(7, $variant->fresh()->available_quantity_cache);
        $this->assertNull($record->inventory_location_id);
        $this->assertNull($record->location_name_snapshot);
        $this->assertSame(-3, $record->quantity_change);
        $this->assertSame(7, $record->resulting_quantity);
    }

    public function test_reservation_releaser_is_idempotent(): void
    {
        $variant = $this->createVariantWithCache(10);

        $reservation = Reservation::create([
            'workspace_id' => $variant->workspace_id,
            'customer_id' => $this->createCustomer()->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->addHour(),
        ]);

        $releaser = app(ReservationReleaser::class);
        $releaser->release($reservation, 'cancelled');
        $releaser->release($reservation->fresh(), 'cancelled');

        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
        $this->assertSame(10, app(AvailabilityResolver::class)->netAvailable($variant->fresh()));
    }

    public function test_reservation_releaser_rejects_confirmed_reservation(): void
    {
        $variant = $this->createVariantWithCache(10);

        $reservation = Reservation::create([
            'workspace_id' => $variant->workspace_id,
            'customer_id' => $this->createCustomer()->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'status' => ReservationStatus::Confirmed,
            'expires_at' => now()->addHour(),
        ]);

        $this->expectException(InvalidReservationTransitionException::class);

        app(ReservationReleaser::class)->release($reservation, 'cancelled');
    }

    public function test_expire_command_is_idempotent(): void
    {
        $variant = $this->createVariantWithCache(10);

        $reservation = Reservation::create([
            'workspace_id' => $variant->workspace_id,
            'customer_id' => $this->createCustomer()->id,
            'variant_id' => $variant->id,
            'quantity' => 3,
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('reservations:expire')->assertSuccessful();
        $this->artisan('reservations:expire')->assertSuccessful();

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        $this->assertSame(10, app(AvailabilityResolver::class)->netAvailable($variant->fresh()));
    }
}
