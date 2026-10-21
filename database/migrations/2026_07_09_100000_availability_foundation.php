<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! Schema::hasColumn('stocks', 'workspace_id')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->foreignUuid('workspace_id')->nullable()->after('id')->constrained('workspaces');
            });
        }

        if (Schema::hasColumn('stocks', 'workspace_id')) {
            $stockWorkspaceRows = DB::table('stocks')
                ->join('product_variants', 'stocks.variant_id', '=', 'product_variants.id')
                ->whereNull('stocks.workspace_id')
                ->select('stocks.id', 'product_variants.workspace_id')
                ->get();

            foreach ($stockWorkspaceRows as $row) {
                DB::table('stocks')->where('id', $row->id)->update(['workspace_id' => $row->workspace_id]);
            }

            if ($this->columnIsNullable('stocks', 'workspace_id')) {
                $this->makeColumnNotNull('stocks', 'workspace_id', 'CHAR(36)');
            }
        }

        if (! Schema::hasTable('inventory_locations')) {
            Schema::create('inventory_locations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('workspace_id')->constrained('workspaces');
                $table->string('name');
                $table->string('type')->default('warehouse');
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['workspace_id', 'name']);
            });
        }

        $locationIdByKey = [];

        if (Schema::hasColumn('stocks', 'warehouse_name')) {
            $locationPairs = DB::table('stocks')
                ->select('workspace_id', 'warehouse_name')
                ->distinct()
                ->get();

            foreach ($locationPairs as $pair) {
                $key = $pair->workspace_id.'|'.$pair->warehouse_name;

                $existingLocationId = DB::table('inventory_locations')
                    ->where('workspace_id', $pair->workspace_id)
                    ->where('name', $pair->warehouse_name)
                    ->value('id');

                if ($existingLocationId !== null) {
                    $locationIdByKey[$key] = $existingLocationId;

                    continue;
                }

                $id = (string) Str::uuid();
                $locationIdByKey[$key] = $id;

                DB::table('inventory_locations')->insert([
                    'id' => $id,
                    'workspace_id' => $pair->workspace_id,
                    'name' => $pair->warehouse_name,
                    'type' => 'warehouse',
                    'is_default' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (! Schema::hasColumn('stocks', 'inventory_location_id')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->foreignUuid('inventory_location_id')->nullable()->after('workspace_id')->constrained('inventory_locations');
            });
        }

        if (
            Schema::hasColumn('stocks', 'warehouse_name')
            && Schema::hasColumn('stocks', 'inventory_location_id')
        ) {
            $stocks = DB::table('stocks')
                ->whereNull('inventory_location_id')
                ->select('id', 'workspace_id', 'warehouse_name')
                ->get();

            foreach ($stocks as $stock) {
                $key = $stock->workspace_id.'|'.$stock->warehouse_name;
                $locationId = $locationIdByKey[$key] ?? null;

                if ($locationId === null) {
                    throw new RuntimeException("Missing inventory location for stock id {$stock->id}");
                }

                DB::table('stocks')->where('id', $stock->id)->update([
                    'inventory_location_id' => $locationId,
                ]);
            }
        }

        if (
            Schema::hasColumn('stocks', 'inventory_location_id')
            && $this->columnIsNullable('stocks', 'inventory_location_id')
        ) {
            $this->makeColumnNotNull('stocks', 'inventory_location_id', 'CHAR(36)');
        }

        if (
            Schema::hasColumn('stocks', 'warehouse_name')
            && ! $this->indexExists('stocks', 'stocks_variant_id_index')
        ) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->index('variant_id');
            });
        }

        if (Schema::hasColumn('stocks', 'warehouse_name')) {
            if ($this->indexExists('stocks', 'stocks_variant_id_warehouse_name_unique')) {
                Schema::table('stocks', function (Blueprint $table) {
                    $table->dropUnique(['variant_id', 'warehouse_name']);
                });
            }

            Schema::table('stocks', function (Blueprint $table) {
                $table->dropColumn('warehouse_name');
            });

            if (! $this->indexExists('stocks', 'stocks_workspace_id_variant_id_inventory_location_id_unique')) {
                Schema::table('stocks', function (Blueprint $table) {
                    $table->unique(['workspace_id', 'variant_id', 'inventory_location_id']);
                });
            }
        }

        if (Schema::hasColumn('stocks', 'reserved')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->dropColumn('reserved');
            });
        }

        if (! Schema::hasColumn('product_variants', 'available_quantity_cache')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->unsignedInteger('available_quantity_cache')->default(0)->after('is_active');
                $table->enum('availability_status', ['in_stock', 'low_stock', 'out_of_stock', 'pre_order'])
                    ->default('out_of_stock')
                    ->after('available_quantity_cache');
            });
        }

        if (Schema::hasColumn('product_variants', 'available_quantity_cache')) {
            $variantQuantities = DB::table('stocks')
                ->select('variant_id', DB::raw('SUM(quantity) as total_qty'))
                ->groupBy('variant_id')
                ->get();

            foreach ($variantQuantities as $row) {
                $cache = (int) $row->total_qty;
                $status = $cache > 0 ? 'in_stock' : 'out_of_stock';

                DB::table('product_variants')
                    ->where('id', $row->variant_id)
                    ->update([
                        'available_quantity_cache' => $cache,
                        'availability_status' => $status,
                    ]);
            }
        }

        if (! Schema::hasColumn('reservations', 'workspace_id')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->foreignUuid('workspace_id')->nullable()->after('id')->constrained('workspaces');
            });
        }

        if (! Schema::hasColumn('reservations', 'order_id')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->foreignId('order_id')->nullable()->after('contractor_id')->constrained('orders')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('reservations', 'order_item_id')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->foreignId('order_item_id')->nullable()->after('order_id')->constrained('order_items')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('reservations', 'workspace_id')) {
            $reservationWorkspaceRows = DB::table('reservations')
                ->join('product_variants', 'reservations.variant_id', '=', 'product_variants.id')
                ->whereNull('reservations.workspace_id')
                ->select('reservations.id', 'product_variants.workspace_id')
                ->get();

            foreach ($reservationWorkspaceRows as $row) {
                DB::table('reservations')->where('id', $row->id)->update(['workspace_id' => $row->workspace_id]);
            }

            if ($this->columnIsNullable('reservations', 'workspace_id')) {
                $this->makeColumnNotNull('reservations', 'workspace_id', 'CHAR(36)');
            }
        }

        $unexpectedStatuses = DB::table('reservations')
            ->whereNotIn('status', ['active', 'pending', 'confirmed', 'cancelled', 'expired'])
            ->count();

        if ($unexpectedStatuses > 0) {
            throw new RuntimeException(
                "Found {$unexpectedStatuses} reservations with unexpected status values. Migration aborted."
            );
        }

        $this->migrateReservationStatusToPending($driver);

        if (! Schema::hasTable('inventory_records')) {
            Schema::create('inventory_records', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('workspace_id')->constrained('workspaces');
                $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
                $table->foreignUuid('inventory_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
                $table->string('location_name_snapshot')->nullable();
                $table->enum('source_type', ['manual_adjustment', 'bulk_import', 'connector_sync', 'order_allocation']);
                $table->string('source_reference_id')->nullable();
                $table->integer('quantity_change');
                $table->integer('resulting_quantity');
                $table->string('reason')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::dropIfExists('inventory_records');

        $this->migrateReservationStatusToActive($driver);

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
            $table->dropColumn('order_item_id');
            $table->dropForeign(['order_id']);
            $table->dropColumn('order_id');
            $table->dropForeign(['workspace_id']);
            $table->dropColumn('workspace_id');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['available_quantity_cache', 'availability_status']);
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->index('workspace_id');
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'variant_id', 'inventory_location_id']);
            $table->string('warehouse_name')->nullable();
            // Non-restorative: original reserved values cannot be recovered.
            $table->unsignedInteger('reserved')->default(0);
        });

        $stocks = DB::table('stocks')
            ->join('inventory_locations', 'stocks.inventory_location_id', '=', 'inventory_locations.id')
            ->select('stocks.id', 'inventory_locations.name')
            ->get();

        foreach ($stocks as $stock) {
            DB::table('stocks')->where('id', $stock->id)->update([
                'warehouse_name' => $stock->name,
            ]);
        }

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropForeign(['inventory_location_id']);
            $table->dropColumn('inventory_location_id');
            $table->unique(['variant_id', 'warehouse_name']);
            $table->dropIndex(['variant_id']);
        });

        Schema::dropIfExists('inventory_locations');

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropColumn('workspace_id');
        });
    }

    private function migrateReservationStatusToPending(string $driver): void
    {
        if ($driver === 'mysql') {
            $enumValues = $this->getColumnEnumValues('reservations', 'status');

            if (! in_array('pending', $enumValues, true)) {
                DB::statement("ALTER TABLE reservations MODIFY status ENUM('active', 'pending', 'confirmed', 'cancelled', 'expired') NOT NULL DEFAULT 'active'");
            }
        }

        DB::table('reservations')->where('status', 'active')->update(['status' => 'pending']);

        if ($driver === 'mysql') {
            $enumValues = $this->getColumnEnumValues('reservations', 'status');

            if (in_array('active', $enumValues, true)) {
                DB::statement("ALTER TABLE reservations MODIFY status ENUM('pending', 'confirmed', 'cancelled', 'expired') NOT NULL DEFAULT 'pending'");
            }
        }
    }

    private function migrateReservationStatusToActive(string $driver): void
    {
        if ($driver === 'mysql') {
            $enumValues = $this->getColumnEnumValues('reservations', 'status');

            if (! in_array('active', $enumValues, true)) {
                DB::statement("ALTER TABLE reservations MODIFY status ENUM('active', 'pending', 'confirmed', 'cancelled', 'expired') NOT NULL DEFAULT 'active'");
            }
        }

        DB::table('reservations')->where('status', 'pending')->update(['status' => 'active']);

        if ($driver === 'mysql') {
            $enumValues = $this->getColumnEnumValues('reservations', 'status');

            if (in_array('pending', $enumValues, true)) {
                DB::statement("ALTER TABLE reservations MODIFY status ENUM('active', 'confirmed', 'cancelled', 'expired') NOT NULL DEFAULT 'active'");
            }
        }
    }

    private function makeColumnNotNull(string $table, string $column, string $mysqlType): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} {$mysqlType} NOT NULL");
        }
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

    /**
     * @return list<string>
     */
    private function getColumnEnumValues(string $table, string $column): array
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return [];
        }

        $result = DB::selectOne("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

        if ($result === null || ! isset($result->Type)) {
            return [];
        }

        if (! preg_match("/^enum\\('(.+)'\\)$/i", $result->Type, $matches)) {
            return [];
        }

        return array_map(
            static fn (string $value) => str_replace("''", "'", $value),
            explode("','", $matches[1])
        );
    }
};
