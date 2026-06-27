<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time migration of the scattered price columns into product_prices.
     *
     *   prices.price_with_vat            -> contract_price (per contractor)
     *   prices.recommended_retail_price  -> list_price     (contractor-less, collapsed)
     *   products.cost_price              -> cost_of_goods_sold (per variant; column may
     *                                       not exist in this codebase yet — handled defensively)
     */
    public function up(): void
    {
        $now = now();

        $typeIds = DB::table('price_types')->pluck('id', 'code');
        $contractTypeId = $typeIds['contract_price'] ?? null;
        $listTypeId = $typeIds['list_price'] ?? null;
        $costTypeId = $typeIds['cost_of_goods_sold'] ?? null;

        if (! $contractTypeId || ! $listTypeId || ! $costTypeId) {
            throw new RuntimeException('price_types must be seeded before migrating prices.');
        }

        // Nothing to migrate (e.g. fresh install — seeders populate product_prices directly).
        if (! Schema::hasTable('prices')) {
            return;
        }

        // ── contract_price: 1 row per existing prices row ─────────────────────
        $contractRows = [];
        DB::table('prices')
            ->whereNotNull('price_with_vat')
            ->orderBy('id')
            ->each(function ($p) use (&$contractRows, $contractTypeId, $now) {
                $contractRows[] = [
                    'variant_id' => $p->variant_id,
                    'contractor_id' => $p->contractor_id,
                    'price_type_id' => $contractTypeId,
                    'value' => $p->price_with_vat,
                    'currency' => $p->currency ?? 'UAH',
                    'source' => 'import',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            });

        foreach (array_chunk($contractRows, 500) as $chunk) {
            DB::table('product_prices')->insert($chunk);
        }

        // ── list_price (РРЦ): collapse to a single contractor-less row per variant ──
        // Surface — never silently collapse — variants whose RRP actually differs across
        // contractors, so the divergence is visible before we pick a single value.
        $divergent = DB::table('prices')
            ->whereNotNull('recommended_retail_price')
            ->select('variant_id')
            ->groupBy('variant_id')
            ->havingRaw('COUNT(DISTINCT recommended_retail_price) > 1')
            ->pluck('variant_id');

        if ($divergent->isNotEmpty()) {
            $details = DB::table('prices')
                ->whereIn('variant_id', $divergent)
                ->whereNotNull('recommended_retail_price')
                ->select('variant_id', 'recommended_retail_price')
                ->distinct()
                ->orderBy('variant_id')
                ->get()
                ->groupBy('variant_id')
                ->map(fn ($rows) => $rows->pluck('recommended_retail_price')->all())
                ->toArray();

            $message = 'recommended_retail_price diverges across contractors for variants: '
                .json_encode($details, JSON_UNESCAPED_UNICODE);
            Log::warning('[migrate_prices_to_product_prices] '.$message);
            // Also surface on the migration console so it is not missed during a manual run.
            fwrite(STDERR, "\n  [WARN] {$message}\n  Collapsing to MAX(recommended_retail_price) per variant.\n");
        }

        $listRows = DB::table('prices')
            ->whereNotNull('recommended_retail_price')
            ->select('variant_id', DB::raw('MAX(recommended_retail_price) as rrp'), DB::raw('MAX(currency) as currency'))
            ->groupBy('variant_id')
            ->get()
            ->map(fn ($r) => [
                'variant_id' => $r->variant_id,
                'contractor_id' => null,
                'price_type_id' => $listTypeId,
                'value' => $r->rrp,
                'currency' => $r->currency ?? 'UAH',
                'source' => 'import',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($listRows, 500) as $chunk) {
            DB::table('product_prices')->insert($chunk);
        }

        // ── cost_of_goods_sold: from products.cost_price, duplicated across variants ──
        // The current schema has no products.cost_price column; guard so this migration
        // is safe today and automatically picks up the data if the column is added later.
        if (Schema::hasColumn('products', 'cost_price')) {
            $costRows = [];
            DB::table('products')
                ->whereNotNull('cost_price')
                ->orderBy('id')
                ->each(function ($product) use (&$costRows, $costTypeId, $now) {
                    DB::table('product_variants')
                        ->where('product_id', $product->id)
                        ->orderBy('id')
                        ->each(function ($variant) use (&$costRows, $product, $costTypeId, $now) {
                            $costRows[] = [
                                'variant_id' => $variant->id,
                                'contractor_id' => null,
                                'price_type_id' => $costTypeId,
                                'value' => $product->cost_price,
                                'currency' => 'UAH',
                                'source' => 'import',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        });
                });

            foreach (array_chunk($costRows, 500) as $chunk) {
                DB::table('product_prices')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        DB::table('product_prices')->whereIn('price_type_id', function ($q) {
            $q->select('id')->from('price_types')
                ->whereIn('code', ['contract_price', 'list_price', 'cost_of_goods_sold']);
        })->where('source', 'import')->delete();
    }
};
