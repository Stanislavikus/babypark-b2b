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

        $this->dropDefaultPriceListForeignKey('contractors');
        $this->dropChildForeignKeys('contractor_id');
        $this->renameChildColumns('contractor_id', 'customer_id');
        Schema::rename('contractors', 'customers');
        $this->renameSelfConstraints('customers', 'contractors', 'customers');
        $this->restoreChildForeignKeys('customer_id', 'customers');
        $this->restoreDefaultPriceListForeignKey('customers');
        $this->migrateSyncLogType(toCustomers: true);
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        $this->dropDefaultPriceListForeignKey('customers');
        $this->dropChildForeignKeys('customer_id');
        $this->renameChildColumns('customer_id', 'contractor_id');
        Schema::rename('customers', 'contractors');
        $this->renameSelfConstraints('contractors', 'customers', 'contractors');
        $this->restoreChildForeignKeys('contractor_id', 'contractors');
        $this->restoreDefaultPriceListForeignKey('contractors');
        $this->migrateSyncLogType(toCustomers: false);
    }

    private function constraintNameForDefaultPriceList(string $table): string
    {
        return $table === 'customers'
            ? 'customers_workspace_default_price_list_fk'
            : 'contractors_workspace_default_price_list_fk';
    }

    private function dropDefaultPriceListForeignKey(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'default_price_list_id')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $this->dropForeignIfExists($table, $this->constraintNameForDefaultPriceList($table));
        } elseif ($this->sqliteForeignKeyExistsOnColumns($table, ['default_price_list_id'])) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['default_price_list_id']);
            });
        }
    }

    private function restoreDefaultPriceListForeignKey(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'default_price_list_id')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $constraint = $this->constraintNameForDefaultPriceList($table);

            if ($this->foreignKeyExists($table, $constraint)) {
                return;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($constraint) {
                $blueprint->foreign(
                    ['workspace_id', 'default_price_list_id'],
                    $constraint
                )->references(['workspace_id', 'id'])->on('price_lists')->restrictOnDelete();
            });
        } elseif (! $this->sqliteForeignKeyExistsOnColumns($table, ['default_price_list_id'])) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('default_price_list_id')
                    ->references('id')
                    ->on('price_lists')
                    ->nullOnDelete();
            });
        }
    }

    private function renameSelfConstraints(string $table, string $fromPrefix, string $toPrefix): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->renameIndexIfExists($table, "{$fromPrefix}_login_unique", "{$toPrefix}_login_unique");
        $this->renameIndexIfExists($table, "{$fromPrefix}_onec_guid_unique", "{$toPrefix}_onec_guid_unique");

        $this->dropForeignIfExists($table, "{$fromPrefix}_account_manager_id_foreign");
        $this->dropForeignIfExists($table, "{$fromPrefix}_backup_manager_id_foreign");
        $this->dropForeignIfExists($table, "{$fromPrefix}_workspace_id_foreign");

        Schema::table($table, function (Blueprint $blueprint) use ($table, $toPrefix) {
            if (! $this->foreignKeyExists($table, "{$toPrefix}_account_manager_id_foreign")) {
                $blueprint->foreign('account_manager_id', "{$toPrefix}_account_manager_id_foreign")
                    ->references('id')->on('users')->nullOnDelete();
            }

            if (! $this->foreignKeyExists($table, "{$toPrefix}_backup_manager_id_foreign")) {
                $blueprint->foreign('backup_manager_id', "{$toPrefix}_backup_manager_id_foreign")
                    ->references('id')->on('users')->nullOnDelete();
            }

            if (! $this->foreignKeyExists($table, "{$toPrefix}_workspace_id_foreign")) {
                $blueprint->foreign('workspace_id', "{$toPrefix}_workspace_id_foreign")
                    ->references('id')->on('workspaces');
            }
        });
    }

    private function dropChildForeignKeys(string $column): void
    {
        foreach (array_keys($this->childForeignKeyDefinitions()) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (DB::getDriverName() === 'mysql') {
                $this->dropForeignIfExists($table, "{$table}_{$column}_foreign");

                if ($table === 'prices') {
                    $this->dropUniqueIfExists($table, "{$table}_{$column}_variant_id_unique");
                }
            } elseif ($this->sqliteForeignKeyExistsOnColumns($table, [$column])) {
                Schema::table($table, function (Blueprint $blueprint) use ($column, $table) {
                    $blueprint->dropForeign([$column]);

                    if ($table === 'prices') {
                        $blueprint->dropUnique([$column, 'variant_id']);
                    }
                });
            }
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

            if (DB::getDriverName() === 'mysql') {
                $foreignName = "{$table}_{$column}_foreign";

                if ($this->foreignKeyExists($table, $foreignName)) {
                    continue;
                }
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $column, $parentTable, $onDelete) {
                $foreign = $blueprint->foreign($column)->references('id')->on($parentTable);

                if ($onDelete === 'null') {
                    $foreign->nullOnDelete();
                } else {
                    $foreign->cascadeOnDelete();
                }

                if ($table === 'prices') {
                    $uniqueName = "{$table}_{$column}_variant_id_unique";

                    if (DB::getDriverName() !== 'mysql' || ! $this->uniqueIndexExists($table, $uniqueName)) {
                        $blueprint->unique([$column, 'variant_id']);
                    }
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

    private function dropForeignIfExists(string $table, string $constraint): void
    {
        if (DB::getDriverName() !== 'mysql' || ! $this->foreignKeyExists($table, $constraint)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($constraint) {
            $blueprint->dropForeign($constraint);
        });
    }

    private function dropUniqueIfExists(string $table, string $indexName): void
    {
        if (DB::getDriverName() !== 'mysql' || ! $this->uniqueIndexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropIndex($indexName);
        });
    }

    private function renameIndexIfExists(string $table, string $from, string $to): void
    {
        if (DB::getDriverName() !== 'mysql' || ! $this->indexExists($table, $from)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($from, $to) {
            $blueprint->renameIndex($from, $to);
        });
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    private function uniqueIndexExists(string $table, string $indexName): bool
    {
        return $this->indexExists($table, $indexName)
            && DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('INDEX_NAME', $indexName)
                ->where('NON_UNIQUE', 0)
                ->exists();
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }

    /**
     * @param  list<string>  $columns
     */
    private function sqliteForeignKeyExistsOnColumns(string $table, array $columns): bool
    {
        if (DB::getDriverName() !== 'sqlite') {
            return false;
        }

        $foreignKeys = DB::select('PRAGMA foreign_key_list('.$table.')');
        $targetColumns = collect($columns)->sort()->values()->all();

        foreach ($foreignKeys as $foreignKey) {
            $fkColumns = collect($foreignKeys)
                ->where('id', $foreignKey->id)
                ->pluck('from')
                ->sort()
                ->values()
                ->all();

            if ($fkColumns === $targetColumns) {
                return true;
            }
        }

        return false;
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

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE sync_logs MODIFY COLUMN type ENUM('products', 'prices', 'stocks', '{$from}', '{$to}', 'statuses') NOT NULL"
            );
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
