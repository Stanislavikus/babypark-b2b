<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private array $contractorSingleWorkspaceFallbackReports = [];

    /** @var list<string> */
    private array $basePriceCacheDisagreementReports = [];

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! Schema::hasColumn('contractors', 'workspace_id')) {
            Schema::table('contractors', function (Blueprint $table) {
                $table->foreignUuid('workspace_id')->nullable()->after('id')->constrained('workspaces');
            });
        }

        if (Schema::hasColumn('contractors', 'workspace_id')) {
            $this->backfillContractorWorkspaces();

            if ($this->columnIsNullable('contractors', 'workspace_id')) {
                $this->makeColumnNotNull('contractors', 'workspace_id', 'CHAR(36)');
            }
        }

        if (! Schema::hasColumn('product_variants', 'cost_price')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->decimal('cost_price', 15, 2)->nullable()->after('availability_status');
                $table->decimal('recommended_retail_price_cache', 15, 2)->nullable()->after('cost_price');
                $table->decimal('base_price_cache', 15, 2)->nullable()->after('recommended_retail_price_cache');
            });
        }

        $this->copyProductCostPriceToVariants();

        if (! Schema::hasTable('price_lists')) {
            Schema::create('price_lists', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('workspace_id')->constrained('workspaces');
                $table->string('name');
                $table->string('currency', 3)->default('UAH');
                $table->boolean('is_default')->default(false);
                $table->integer('priority')->default(0);
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamps();

                $table->unique(['workspace_id', 'id'], 'price_lists_workspace_id_id_unique');
            });

            if ($driver === 'mysql') {
                DB::statement('
                    ALTER TABLE price_lists
                    ADD COLUMN default_workspace_key CHAR(36)
                    AS (CASE WHEN is_default = 1 THEN workspace_id ELSE NULL END) VIRTUAL
                ');

                Schema::table('price_lists', function (Blueprint $table) {
                    $table->unique('default_workspace_key', 'price_lists_default_workspace_key_unique');
                });
            }
        }

        if (! Schema::hasTable('price_list_items')) {
            if (! $this->indexExists('product_variants', 'product_variants_workspace_id_id_unique')) {
                Schema::table('product_variants', function (Blueprint $table) {
                    $table->unique(['workspace_id', 'id'], 'product_variants_workspace_id_id_unique');
                });
            }

            Schema::create('price_list_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('workspace_id')->constrained('workspaces');
                $table->foreignUuid('price_list_id')->constrained('price_lists')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
                $table->unsignedInteger('quantity_min')->default(1);
                $table->decimal('price', 15, 2);
                $table->decimal('sale_price', 15, 2)->nullable();
                $table->decimal('vat_rate', 5, 2)->nullable();
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->enum('status', ['active', 'suspended'])->default('active');
                $table->timestamps();

                $table->unique(
                    ['workspace_id', 'price_list_id', 'product_variant_id', 'quantity_min'],
                    'price_list_items_ws_list_variant_qty_unique'
                );
            });

            if ($driver === 'mysql') {
                Schema::table('price_list_items', function (Blueprint $table) {
                    $table->foreign(
                        ['workspace_id', 'price_list_id'],
                        'price_list_items_workspace_price_list_fk'
                    )->references(['workspace_id', 'id'])->on('price_lists')->cascadeOnDelete();

                    $table->foreign(
                        ['workspace_id', 'product_variant_id'],
                        'price_list_items_workspace_variant_fk'
                    )->references(['workspace_id', 'id'])->on('product_variants')->cascadeOnDelete();
                });
            }
        }

        if (! Schema::hasColumn('contractors', 'default_price_list_id')) {
            Schema::table('contractors', function (Blueprint $table) {
                $table->uuid('default_price_list_id')->nullable()->after('workspace_id');
            });

            if ($driver === 'mysql') {
                Schema::table('contractors', function (Blueprint $table) {
                    $table->foreign(
                        ['workspace_id', 'default_price_list_id'],
                        'contractors_workspace_default_price_list_fk'
                    )->references(['workspace_id', 'id'])->on('price_lists')->restrictOnDelete();
                });
            } else {
                Schema::table('contractors', function (Blueprint $table) {
                    $table->foreign('default_price_list_id')
                        ->references('id')
                        ->on('price_lists')
                        ->nullOnDelete();
                });
            }
        }

        if (Schema::hasTable('prices') && DB::table('prices')->exists()) {
            $this->guardLegacyPriceWorkspaceConsistency();
            $this->guardRecommendedRetailPriceAgreement();
            $this->migrateLegacyPrices();
            $this->backfillVariantPriceCaches();
        } else {
            $this->ensureWorkspaceDefaultPriceLists();
        }

        if ($this->contractorSingleWorkspaceFallbackReports !== []) {
            foreach ($this->contractorSingleWorkspaceFallbackReports as $report) {
                fwrite(STDERR, "[pricing-migration] {$report}\n");
            }
        }

        if ($this->basePriceCacheDisagreementReports !== []) {
            foreach ($this->basePriceCacheDisagreementReports as $report) {
                fwrite(STDERR, "[pricing-migration] {$report}\n");
            }
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (Schema::hasColumn('contractors', 'default_price_list_id')) {
            if ($driver === 'mysql') {
                Schema::table('contractors', function (Blueprint $table) {
                    $table->dropForeign('contractors_workspace_default_price_list_fk');
                });
            } else {
                Schema::table('contractors', function (Blueprint $table) {
                    $table->dropForeign(['default_price_list_id']);
                });
            }

            Schema::table('contractors', function (Blueprint $table) {
                $table->dropColumn('default_price_list_id');
            });
        }

        Schema::dropIfExists('price_list_items');

        if (Schema::hasTable('price_lists')) {
            if ($driver === 'mysql') {
                Schema::table('price_lists', function (Blueprint $table) {
                    $table->dropUnique('price_lists_default_workspace_key_unique');
                });

                DB::statement('ALTER TABLE price_lists DROP COLUMN default_workspace_key');
            }

            Schema::dropIfExists('price_lists');
        }

        if (Schema::hasColumn('product_variants', 'cost_price')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropColumn([
                    'cost_price',
                    'recommended_retail_price_cache',
                    'base_price_cache',
                ]);
            });
        }

        if ($this->indexExists('product_variants', 'product_variants_workspace_id_id_unique')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropUnique('product_variants_workspace_id_id_unique');
            });
        }

        if (Schema::hasColumn('contractors', 'workspace_id')) {
            Schema::table('contractors', function (Blueprint $table) {
                $table->dropForeign(['workspace_id']);
                $table->dropColumn('workspace_id');
            });
        }
    }

    private function backfillContractorWorkspaces(): void
    {
        $workspaceCount = DB::table('workspaces')->count();
        $defaultWorkspaceId = DB::table('workspaces')->where('is_default', true)->value('id');

        $contractors = DB::table('contractors')->select('id', 'name')->get();

        foreach ($contractors as $contractor) {
            $workspaces = DB::table('prices')
                ->join('product_variants', 'prices.variant_id', '=', 'product_variants.id')
                ->where('prices.contractor_id', $contractor->id)
                ->distinct()
                ->pluck('product_variants.workspace_id')
                ->filter();

            if ($workspaces->count() > 1) {
                throw new RuntimeException(
                    "Contractor {$contractor->id} ({$contractor->name}) has priced variants in multiple workspaces; cannot backfill workspace_id."
                );
            }

            if ($workspaces->count() === 1) {
                DB::table('contractors')->where('id', $contractor->id)->update([
                    'workspace_id' => $workspaces->first(),
                ]);

                continue;
            }

            if ($workspaceCount > 1) {
                throw new RuntimeException(
                    "Contractor {$contractor->id} ({$contractor->name}) has no priced variants and multiple workspaces exist; cannot choose workspace_id."
                );
            }

            if ($workspaceCount === 0 || $defaultWorkspaceId === null) {
                throw new RuntimeException('No workspace available for contractor workspace_id backfill.');
            }

            DB::table('contractors')->where('id', $contractor->id)->update([
                'workspace_id' => $defaultWorkspaceId,
            ]);

            $this->contractorSingleWorkspaceFallbackReports[] =
                "Contractor {$contractor->id} ({$contractor->name}) assigned workspace_id {$defaultWorkspaceId} via single-workspace fallback (no priced variants).";
        }
    }

    private function copyProductCostPriceToVariants(): void
    {
        $products = DB::table('products')
            ->whereNotNull('cost_price')
            ->select('id', 'cost_price')
            ->get();

        foreach ($products as $product) {
            DB::table('product_variants')
                ->where('product_id', $product->id)
                ->update(['cost_price' => $product->cost_price]);
        }
    }

    private function guardLegacyPriceWorkspaceConsistency(): void
    {
        $mismatches = DB::table('prices')
            ->join('contractors', 'prices.contractor_id', '=', 'contractors.id')
            ->join('product_variants', 'prices.variant_id', '=', 'product_variants.id')
            ->whereNotNull('contractors.workspace_id')
            ->whereColumn('contractors.workspace_id', '!=', 'product_variants.workspace_id')
            ->select('prices.id', 'prices.contractor_id', 'prices.variant_id')
            ->get();

        if ($mismatches->isNotEmpty()) {
            $ids = $mismatches->pluck('id')->implode(', ');

            throw new RuntimeException(
                "Legacy price rows cross workspace boundaries (price ids: {$ids}). Migration aborted."
            );
        }
    }

    private function guardRecommendedRetailPriceAgreement(): void
    {
        $conflicts = DB::table('prices')
            ->select('variant_id')
            ->whereNotNull('recommended_retail_price')
            ->groupBy('variant_id')
            ->havingRaw('COUNT(DISTINCT recommended_retail_price) > 1')
            ->pluck('variant_id');

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException(
                'recommended_retail_price disagrees across contractors for variant id(s): '
                .$conflicts->implode(', ')
            );
        }
    }

    private function migrateLegacyPrices(): void
    {
        $contractorIds = DB::table('prices')->distinct()->pluck('contractor_id');
        $contractorPriceListIds = [];

        foreach ($contractorIds as $contractorId) {
            $contractor = DB::table('contractors')->where('id', $contractorId)->first();

            if ($contractor === null) {
                continue;
            }

            $priceListId = (string) Str::uuid();

            DB::table('price_lists')->insert([
                'id' => $priceListId,
                'workspace_id' => $contractor->workspace_id,
                'name' => 'Legacy — '.$contractor->name,
                'currency' => 'UAH',
                'is_default' => false,
                'priority' => 0,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $contractorPriceListIds[$contractorId] = $priceListId;

            DB::table('contractors')->where('id', $contractorId)->update([
                'default_price_list_id' => $priceListId,
            ]);
        }

        $legacyPrices = DB::table('prices')
            ->join('contractors', 'prices.contractor_id', '=', 'contractors.id')
            ->join('product_variants', 'prices.variant_id', '=', 'product_variants.id')
            ->select(
                'prices.*',
                'contractors.workspace_id as contractor_workspace_id',
                'product_variants.workspace_id as variant_workspace_id'
            )
            ->get();

        foreach ($legacyPrices as $price) {
            $priceListId = $contractorPriceListIds[$price->contractor_id] ?? null;

            if ($priceListId === null) {
                throw new RuntimeException("Missing legacy price list for contractor {$price->contractor_id}.");
            }

            if ($price->contractor_workspace_id !== $price->variant_workspace_id) {
                throw new RuntimeException(
                    "Legacy price {$price->id} would cross workspaces (contractor vs variant)."
                );
            }

            DB::table('price_list_items')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => $price->contractor_workspace_id,
                'price_list_id' => $priceListId,
                'product_variant_id' => $price->variant_id,
                'quantity_min' => $price->min_quantity,
                'price' => $price->price,
                'sale_price' => null,
                'vat_rate' => $price->vat_rate,
                'valid_from' => null,
                'valid_until' => null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->ensureWorkspaceDefaultPriceLists();
    }

    private function ensureWorkspaceDefaultPriceLists(): void
    {
        $workspaceIds = DB::table('workspaces')->pluck('id');

        foreach ($workspaceIds as $workspaceId) {
            $existingDefault = DB::table('price_lists')
                ->where('workspace_id', $workspaceId)
                ->where('is_default', true)
                ->where('status', 'active')
                ->count();

            if ($existingDefault > 0) {
                continue;
            }

            DB::table('price_lists')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'name' => 'Workspace Default',
                'currency' => 'UAH',
                'is_default' => true,
                'priority' => 0,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function backfillVariantPriceCaches(): void
    {
        $variantIds = DB::table('prices')->distinct()->pluck('variant_id');

        foreach ($variantIds as $variantId) {
            $rrpValues = DB::table('prices')
                ->where('variant_id', $variantId)
                ->whereNotNull('recommended_retail_price')
                ->distinct()
                ->pluck('recommended_retail_price');

            $rrpCache = $rrpValues->count() === 1 ? $rrpValues->first() : ($rrpValues->count() === 0 ? null : null);

            if ($rrpValues->count() > 1) {
                throw new RuntimeException(
                    "recommended_retail_price disagreement for variant {$variantId} during cache backfill."
                );
            }

            if ($rrpValues->count() === 1) {
                $rrpCache = $rrpValues->first();
            }

            $netPrices = DB::table('prices')
                ->where('variant_id', $variantId)
                ->distinct()
                ->pluck('price');

            $baseCache = null;

            if ($netPrices->count() === 1) {
                $baseCache = $netPrices->first();
            } elseif ($netPrices->count() > 1) {
                $this->basePriceCacheDisagreementReports[] =
                    "Variant {$variantId}: base_price_cache left null — net price values disagree across contractors ({$netPrices->implode(', ')}).";
            }

            DB::table('product_variants')->where('id', $variantId)->update([
                'recommended_retail_price_cache' => $rrpCache,
                'base_price_cache' => $baseCache,
            ]);
        }
    }

    private function makeColumnNotNull(string $table, string $column, string $mysqlType): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} {$mysqlType} NOT NULL");
        }
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $columns = DB::select("PRAGMA table_info({$table})");

            foreach ($columns as $info) {
                if ($info->name === $column) {
                    return (int) $info->notnull === 0;
                }
            }

            return false;
        }

        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::table('information_schema.columns')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('is_nullable');

        return $row === 'YES';
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $result = DB::selectOne(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
                [$table, $indexName]
            );

            return $result !== null;
        }

        $database = Schema::getConnection()->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
