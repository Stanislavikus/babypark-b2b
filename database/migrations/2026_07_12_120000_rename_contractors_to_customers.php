<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contractors')) {
            return;
        }

        $this->dropContractorsDefaultPriceListForeignKey();
        $this->dropChildForeignKeys('contractor_id');
        $this->renameChildColumns('contractor_id', 'customer_id');
        Schema::rename('contractors', 'customers');
        $this->restoreChildForeignKeys('customer_id', 'customers');
        $this->restoreContractorsDefaultPriceListForeignKey('customers');
        $this->migrateSyncLogType(toCustomers: true);
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        $this->dropContractorsDefaultPriceListForeignKey('customers');
        $this->dropChildForeignKeys('customer_id');
        $this->renameChildColumns('customer_id', 'contractor_id');
        Schema::rename('customers', 'contractors');
        $this->restoreChildForeignKeys('contractor_id', 'contractors');
        $this->restoreContractorsDefaultPriceListForeignKey('contractors');
        $this->migrateSyncLogType(toCustomers: false);
    }

    private function dropContractorsDefaultPriceListForeignKey(string $table = 'contractors'): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'default_price_list_id')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            try {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropForeign('contractors_workspace_default_price_list_fk');
                });
            } catch (Throwable) {
                // FK may already be absent.
            }
        } else {
            try {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropForeign(['default_price_list_id']);
                });
            } catch (Throwable) {
                // FK may already be absent.
            }
        }
    }

    private function restoreContractorsDefaultPriceListForeignKey(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'default_price_list_id')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $constraint = $table === 'customers'
                    ? 'customers_workspace_default_price_list_fk'
                    : 'contractors_workspace_default_price_list_fk';

                $blueprint->foreign(
                    ['workspace_id', 'default_price_list_id'],
                    $constraint
                )->references(['workspace_id', 'id'])->on('price_lists')->restrictOnDelete();
            });
        } else {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('default_price_list_id')
                    ->references('id')
                    ->on('price_lists')
                    ->nullOnDelete();
            });
        }
    }

    private function dropChildForeignKeys(string $column): void
    {
        foreach (array_keys($this->childForeignKeyDefinitions()) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $column) {
                try {
                    $blueprint->dropForeign([$column]);
                } catch (Throwable) {
                    // Already dropped or unnamed on SQLite.
                }

                if ($table === 'prices') {
                    try {
                        $blueprint->dropUnique([$column, 'variant_id']);
                    } catch (Throwable) {
                        // Unique index may already be absent.
                    }
                }
            });
        }
    }

    private function renameChildColumns(string $from, string $to): void
    {
        foreach (array_keys($this->childForeignKeyDefinitions()) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $from)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($from, $to) {
                $blueprint->renameColumn($from, $to);
            });
        }
    }

    private function restoreChildForeignKeys(string $column, string $parentTable): void
    {
        foreach ($this->childForeignKeyDefinitions() as $table => $onDelete) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $column, $parentTable, $onDelete) {
                $foreign = $blueprint->foreign($column)->references('id')->on($parentTable);

                if ($onDelete === 'null') {
                    $foreign->nullOnDelete();
                } else {
                    $foreign->cascadeOnDelete();
                }

                if ($table === 'prices') {
                    $blueprint->unique([$column, 'variant_id']);
                }
            });
        }
    }

    /**
     * @return array<string, 'cascade'|'null'>
     */
    private function childForeignKeyDefinitions(): array
    {
        return [
            'users' => 'null',
            'orders' => 'cascade',
            'prices' => 'cascade',
            'reservations' => 'cascade',
        ];
    }

    private function migrateSyncLogType(bool $toCustomers): void
    {
        if (! Schema::hasTable('sync_logs')) {
            return;
        }

        $from = $toCustomers ? 'contractors' : 'customers';
        $to = $toCustomers ? 'customers' : 'contractors';

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSyncLogsEnumForSqlite($from, $to);

            return;
        }

        DB::table('sync_logs')->where('type', $from)->update(['type' => $to]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE sync_logs MODIFY COLUMN type ENUM('products', 'prices', 'stocks', '{$to}', 'statuses') NOT NULL"
            );
        }
    }

    private function rebuildSyncLogsEnumForSqlite(string $from, string $to): void
    {
        $rows = DB::table('sync_logs')->get()->map(function ($row) use ($from, $to) {
            $type = $row->type === $from ? $to : $row->type;

            return [
                'id' => $row->id,
                'type' => $type,
                'status' => $row->status,
                'records_processed' => $row->records_processed,
                'error_message' => $row->error_message,
                'started_at' => $row->started_at,
                'finished_at' => $row->finished_at,
            ];
        })->all();

        Schema::drop('sync_logs');

        Schema::create('sync_logs', function (Blueprint $table) use ($to) {
            $table->id();
            $table->enum('type', ['products', 'prices', 'stocks', $to, 'statuses']);
            $table->enum('status', ['success', 'error']);
            $table->unsignedInteger('records_processed')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
        });

        if ($rows !== []) {
            DB::table('sync_logs')->insert($rows);
        }
    }
};
