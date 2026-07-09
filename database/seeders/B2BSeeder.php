<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PriceListItemStatus;
use App\Enums\PriceListStatus;
use App\Enums\ReservationStatus;
use App\Enums\SyncLogStatus;
use App\Enums\SyncLogType;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Contractor;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Price;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Models\Stock;
use App\Models\SyncLog;
use App\Models\User;
use App\Support\Workspace\WorkspaceContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class B2BSeeder extends Seeder
{
    private const WAREHOUSES = ['Склад Київ', 'Склад Одеса'];

    public function run(): void
    {
        $admin = User::query()->create([
            'name' => 'Адміністратор',
            'email' => 'admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        User::query()->create([
            'name' => 'Менеджер B2B',
            'email' => 'manager@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Manager,
            'is_active' => true,
        ]);

        $contractors = $this->seedContractors();
        $categories = $this->seedCategories();
        $variants = $this->seedProductsAndVariants($categories);
        $this->seedPrices($contractors, $variants);
        $this->seedStocks($variants);
        $this->seedOrders($contractors, $variants, $admin);
        $this->seedReservations($contractors, $variants);
        $this->seedSyncLogs();
    }

    /**
     * @return Collection<int, Contractor>
     */
    private function seedContractors()
    {
        $data = [
            [
                'name' => 'ТОВ «Дитячий Світ»',
                'short_name' => 'Дитячий Світ',
                'login' => 'dytiachyi-svit',
                'credit_limit' => 500000,
                'current_debt' => 125000,
                'payment_delay_days' => 14,
            ],
            [
                'name' => 'ФОП Іваненко О.М.',
                'short_name' => 'Іваненко',
                'login' => 'ivanenko',
                'credit_limit' => 150000,
                'current_debt' => 42000,
                'payment_delay_days' => 7,
            ],
            [
                'name' => 'ТОВ «Малюк Плюс»',
                'short_name' => 'Малюк Плюс',
                'login' => 'malyuk-plus',
                'credit_limit' => 1000000,
                'current_debt' => 310000,
                'payment_delay_days' => 21,
            ],
        ];

        return collect($data)->map(function (array $row, int $index) {
            return Contractor::query()->create([
                'onec_guid' => (string) Str::uuid(),
                'name' => $row['name'],
                'short_name' => $row['short_name'],
                'edrpou' => sprintf('%08d', 38000000 + $index),
                'ipn' => null,
                'manager_name' => 'Менеджер '.($index + 1),
                'manager_phone' => '+38050'.sprintf('%07d', 1000000 + $index),
                'login' => $row['login'],
                'password' => 'password',
                'is_active' => true,
                'payment_delay_days' => $row['payment_delay_days'],
                'credit_limit' => $row['credit_limit'],
                'current_debt' => $row['current_debt'],
                'synced_at' => now(),
            ]);
        });
    }

    /**
     * @return Collection<int, Category>
     */
    private function seedCategories()
    {
        $names = [
            'Коляски та автокрісла',
            'Годування',
            'Іграшки',
            'Одяг для немовлят',
            'Гігієна та догляд',
        ];

        return collect($names)->map(fn (string $name, int $i) => Category::query()->create([
            'onec_guid' => (string) Str::uuid(),
            'name' => $name,
            'stock_display_threshold' => [5, 10, 15, 10, 8][$i],
        ]));
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, ProductVariant>
     */
    private function seedProductsAndVariants($categories)
    {
        $brands = ['Chicco', 'Philips Avent', 'Pampers', 'LEGO DUPLO', 'Fisher-Price'];
        $variants = collect();

        for ($i = 1; $i <= 50; $i++) {
            $category = $categories->random();
            $product = Product::query()->create([
                'onec_guid' => (string) Str::uuid(),
                'sku' => sprintf('BP-%05d', $i),
                'barcode_ean' => sprintf('482%010d', $i),
                'name' => 'Товар BabyPark #'.$i,
                'category_id' => $category->id,
                'brand' => $brands[($i - 1) % count($brands)],
                'cost_price' => round(25 + ($i % 20) * 3.5, 2),
                'unit' => 'шт',
                'min_order_quantity' => $i % 5 === 0 ? 2 : 1,
                'order_step' => 1,
                'package_quantity' => 12,
                'package_type' => 'коробка',
                'units_per_box' => 12,
                'boxes_per_pallet' => 40,
                'lead_time_days' => rand(1, 7),
                'description' => 'Тестовий опис товару #'.$i.' для B2B кабінету.',
                'images' => ['https://picsum.photos/400/400?random='.$i],
                'is_active' => true,
                'synced_at' => now(),
            ]);

            $variant = ProductVariant::query()->create([
                'product_id' => $product->id,
                'onec_guid' => (string) Str::uuid(),
                'sku' => $product->sku.'-V1',
                'barcode_ean' => $product->barcode_ean,
                'attributes' => $i % 3 === 0 ? ['Колір' => 'Синій', 'Розмір' => 'M'] : null,
                'is_active' => true,
                'cost_price' => $product->cost_price,
                'synced_at' => now(),
            ]);

            if ($i % 10 === 0) {
                ProductVariant::query()->create([
                    'product_id' => $product->id,
                    'onec_guid' => (string) Str::uuid(),
                    'sku' => $product->sku.'-V2',
                    'barcode_ean' => sprintf('482%010d', 50000 + $i),
                    'attributes' => ['Колір' => 'Рожевий'],
                    'is_active' => true,
                    'cost_price' => $product->cost_price,
                    'synced_at' => now(),
                ]);
            }

            $variants = $variants->merge($product->variants()->get());
        }

        return $variants;
    }

    /**
     * @param  Collection<int, Contractor>  $contractors
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function seedPrices($contractors, $variants): void
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        PriceList::withoutWorkspaceScope()->firstOrCreate(
            [
                'workspace_id' => $workspaceId,
                'is_default' => true,
            ],
            [
                'name' => 'Workspace Default',
                'currency' => 'UAH',
                'priority' => 0,
                'status' => PriceListStatus::Active,
            ],
        );

        foreach ($contractors as $contractor) {
            $priceList = PriceList::query()->create([
                'name' => 'Legacy — '.$contractor->name,
                'currency' => 'UAH',
                'is_default' => false,
                'priority' => 0,
                'status' => PriceListStatus::Active,
            ]);

            $contractor->update(['default_price_list_id' => $priceList->id]);

            foreach ($variants as $index => $variant) {
                $base = 50 + ($index % 40) * 12.5 + ($contractor->id * 3);
                $vatRate = 20;
                $price = round($base, 2);
                $priceWithVat = round($price * (1 + $vatRate / 100), 2);
                $rrp = round((50 + ($index % 40) * 12.5) * 1.2 * 1.35, 2);

                Price::query()->create([
                    'contractor_id' => $contractor->id,
                    'variant_id' => $variant->id,
                    'price' => $price,
                    'price_with_vat' => $priceWithVat,
                    'vat_rate' => $vatRate,
                    'recommended_retail_price' => $rrp,
                    'min_quantity' => 1,
                    'currency' => 'UAH',
                ]);

                PriceListItem::query()->create([
                    'price_list_id' => $priceList->id,
                    'product_variant_id' => $variant->id,
                    'quantity_min' => 1,
                    'price' => $price,
                    'sale_price' => null,
                    'vat_rate' => $vatRate,
                    'status' => PriceListItemStatus::Active,
                ]);
            }
        }

        foreach ($variants as $index => $variant) {
            $demoNet = round(50 + ($index % 40) * 12.5, 2);
            $demoGross = round($demoNet * 1.2, 2);

            $variant->update([
                'recommended_retail_price_cache' => round($demoGross * 1.35, 2),
                'base_price_cache' => null,
            ]);
        }
    }

    /**
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function seedStocks($variants): void
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $locations = [];

        foreach (self::WAREHOUSES as $warehouse) {
            $locations[$warehouse] = InventoryLocation::withoutWorkspaceScope()->firstOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'name' => $warehouse,
                ],
                [
                    'type' => 'warehouse',
                    'is_default' => $warehouse === self::WAREHOUSES[0],
                    'is_active' => true,
                ],
            );
        }

        foreach ($variants as $index => $variant) {
            $totalQty = 0;

            foreach (self::WAREHOUSES as $warehouse) {
                $qty = match (true) {
                    $index % 7 === 0 => 0,
                    $index % 5 === 0 => rand(1, 8),
                    default => rand(20, 500),
                };

                $totalQty += $qty;

                Stock::query()->create([
                    'workspace_id' => $workspaceId,
                    'variant_id' => $variant->id,
                    'inventory_location_id' => $locations[$warehouse]->id,
                    'quantity' => $qty,
                    'expected_date' => $qty === 0 ? now()->addDays(rand(3, 21))->toDateString() : null,
                    'expected_quantity' => $qty === 0 ? rand(10, 100) : null,
                    'updated_at' => now(),
                ]);
            }

            $variant->update([
                'available_quantity_cache' => $totalQty,
                'availability_status' => $totalQty > 0 ? 'in_stock' : 'out_of_stock',
            ]);
        }
    }

    /**
     * @param  Collection<int, Contractor>  $contractors
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function seedOrders($contractors, $variants, User $admin): void
    {
        $statuses = [
            OrderStatus::New,
            OrderStatus::Pending,
            OrderStatus::Confirmed,
            OrderStatus::InProgress,
            OrderStatus::Shipped,
            OrderStatus::Delivered,
            OrderStatus::Cancelled,
            OrderStatus::New,
            OrderStatus::Confirmed,
            OrderStatus::InProgress,
        ];

        foreach ($statuses as $i => $status) {
            $contractor = $contractors[$i % $contractors->count()];
            $orderVariants = $variants->random(min(4, $variants->count()));
            $total = 0;
            $totalWithVat = 0;

            $order = Order::query()->create([
                'contractor_id' => $contractor->id,
                'user_id' => $admin->id,
                'onec_number' => $status === OrderStatus::New ? null : '1C-'.(1000 + $i),
                'status' => $status,
                'currency' => 'UAH',
                'comment' => 'Тестове замовлення #'.($i + 1),
                'needs_call' => $i % 4 === 0,
                'transmitted_at' => in_array($status, [OrderStatus::Confirmed, OrderStatus::InProgress, OrderStatus::Shipped, OrderStatus::Delivered], true)
                    ? now()->subDays(rand(1, 5))
                    : null,
            ]);

            foreach ($orderVariants as $variant) {
                $price = Price::query()
                    ->where('contractor_id', $contractor->id)
                    ->where('variant_id', $variant->id)
                    ->first();

                $qty = rand(1, 10);
                $lineTotal = $price->price_with_vat * $qty;
                $total += $price->price * $qty;
                $totalWithVat += $lineTotal;

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'variant_id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->product->name,
                    'attributes' => $variant->attributes,
                    'quantity' => $qty,
                    'price' => $price->price,
                    'price_with_vat' => $price->price_with_vat,
                    'total' => $lineTotal,
                ]);
            }

            $order->update([
                'total' => $total,
                'total_with_vat' => $totalWithVat,
            ]);
        }
    }

    /**
     * @param  Collection<int, Contractor>  $contractors
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function seedReservations($contractors, $variants): void
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        foreach ($contractors as $contractor) {
            Reservation::query()->create([
                'workspace_id' => $workspaceId,
                'contractor_id' => $contractor->id,
                'variant_id' => $variants->random()->id,
                'quantity' => rand(5, 50),
                'status' => ReservationStatus::Pending,
                'expires_at' => now()->addDays(3),
            ]);
        }
    }

    private function seedSyncLogs(): void
    {
        foreach (SyncLogType::cases() as $type) {
            SyncLog::query()->create([
                'type' => $type,
                'status' => SyncLogStatus::Success,
                'records_processed' => rand(10, 500),
                'started_at' => now()->subHours(rand(1, 48)),
                'finished_at' => now()->subHours(rand(0, 47)),
            ]);
        }
    }
}
