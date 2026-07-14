<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GAP-017 Scenario 2 — sanctioned migration roundtrip test.
 *
 * Intentional `contractors` / `contractor_id` literals below exercise the rename
 * migration rollback/re-apply path; they are not runtime domain usage.
 */
class CustomerRenameMigrationTest extends TestCase
{
    public function test_rename_migration_preserves_legacy_contractor_rows_on_roundtrip(): void
    {
        Artisan::call('migrate:fresh');

        if (DB::getDriverName() === 'mysql') {
            $this->assertCustomersTableSchema();
            $this->assertSchemaContainsNoContractorArtifacts();
        }

        $syncLogId = DB::table('sync_logs')->insertGetId([
            'type' => 'customers',
            'status' => 'success',
            'started_at' => now(),
        ]);

        $workspaceId = DB::table('workspaces')->where('is_default', true)->value('id');

        $password = 'legacy-secret';
        $passwordHash = Hash::make($password);

        $customersBeforeRollback = DB::table('customers')->count();

        DB::table('customers')->insert([
            'workspace_id' => $workspaceId,
            'onec_guid' => (string) Str::uuid(),
            'name' => 'Down-path Fixture Customer',
            'login' => 'down-path-fixture',
            'password' => $passwordHash,
            'is_active' => true,
            'payment_delay_days' => 0,
            'credit_limit' => 0,
            'current_debt' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customersBeforeRollback = DB::table('customers')->count();

        Artisan::call('migrate:rollback', ['--step' => 3]);

        $this->assertTrue(Schema::hasTable('contractors'));
        $this->assertFalse(Schema::hasTable('customers'));
        $this->assertSame(
            'contractors',
            DB::table('sync_logs')->where('id', $syncLogId)->value('type'),
            'down() must rename sync_logs.type from customers back to contractors'
        );

        if (DB::getDriverName() === 'mysql') {
            $this->assertSyncLogTypeEnumContains('contractors');
            $this->assertSyncLogTypeEnumDoesNotContain('customers');
        }

        $this->assertTrue(
            DB::table('sync_logs')->where('id', $syncLogId)->where('type', 'contractors')->exists(),
            'sync_logs row with type=contractors must exist before re-applying up()'
        );

        $this->assertSame(
            $customersBeforeRollback,
            DB::table('contractors')->count(),
            'down() must preserve row count when renaming customers back to contractors'
        );

        if (DB::getDriverName() === 'mysql') {
            $this->assertContractorsTableSchema();
            $this->assertSchemaContainsNoCustomerConstraintArtifacts();
        }

        $legacyId = DB::table('contractors')->insertGetId([
            'workspace_id' => $workspaceId,
            'onec_guid' => (string) Str::uuid(),
            'name' => 'Test Legacy Customer',
            'login' => 'legacy-upgrade',
            'password' => $passwordHash,
            'is_active' => true,
            'payment_delay_days' => 7,
            'credit_limit' => 1000,
            'current_debt' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $variantId = DB::table('product_variants')->value('id');

        if ($variantId === null) {
            $productId = DB::table('products')->insertGetId([
                'workspace_id' => $workspaceId,
                'onec_guid' => (string) Str::uuid(),
                'sku' => 'LEGACY-SKU',
                'name' => 'Legacy Product',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $variantId = DB::table('product_variants')->insertGetId([
                'workspace_id' => $workspaceId,
                'product_id' => $productId,
                'onec_guid' => (string) Str::uuid(),
                'sku' => 'LEGACY-VAR',
                'is_active' => true,
                'available_quantity_cache' => 0,
                'availability_status' => 'out_of_stock',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $contractorsCountBeforeUp = DB::table('contractors')->count();

        DB::table('prices')->insert([
            'contractor_id' => $legacyId,
            'variant_id' => $variantId,
            'price' => 99.99,
            'price_with_vat' => 119.99,
            'vat_rate' => 20,
            'min_quantity' => 1,
            'currency' => 'UAH',
            'updated_at' => now(),
        ]);

        Artisan::call('migrate');

        $this->assertTrue(Schema::hasTable('customers'));
        $this->assertFalse(Schema::hasTable('contractors'));
        $this->assertSame(
            $contractorsCountBeforeUp,
            DB::table('customers')->count(),
            'up() must preserve contractor row count after rename'
        );

        if (DB::getDriverName() === 'mysql') {
            $this->assertCustomersTableSchema();
            $this->assertSchemaContainsNoContractorArtifacts();
            $this->assertSyncLogTypeEnumContains('customers');
            $this->assertSyncLogTypeEnumDoesNotContain('contractors');
        }

        $this->assertSame(
            'customers',
            DB::table('sync_logs')->where('id', $syncLogId)->value('type'),
            'up() must rename sync_logs.type from contractors back to customers'
        );

        $migrated = DB::table('customers')->where('id', $legacyId)->first();

        $this->assertNotNull($migrated);
        $this->assertSame('Test Legacy Customer', $migrated->name);
        $this->assertSame($workspaceId, $migrated->workspace_id);
        $this->assertSame(7, (int) $migrated->payment_delay_days);
        $this->assertTrue(Hash::check($password, $migrated->password));

        $priceRow = DB::table('prices')->where('customer_id', $legacyId)->first();

        $this->assertNotNull($priceRow);
        $this->assertSame($legacyId, (int) $priceRow->customer_id);

        $this->assertTrue(
            Auth::guard('customer')->attempt(['login' => 'legacy-upgrade', 'password' => $password])
        );
        $this->assertSame($legacyId, Auth::guard('customer')->id());
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh');

        parent::tearDown();
    }

    private function assertCustomersTableSchema(): void
    {
        $this->assertNamedForeignKeyCount('customers', 'customers_workspace_default_price_list_fk', 1);
        $this->assertNamedForeignKeyCount('customers', 'customers_account_manager_id_foreign', 1);
        $this->assertNamedForeignKeyCount('customers', 'customers_backup_manager_id_foreign', 1);
        $this->assertNamedForeignKeyCount('customers', 'customers_workspace_id_foreign', 1);
        $this->assertNamedUniqueIndexCount('customers', 'customers_login_unique', 1);
        $this->assertNamedUniqueIndexCount('customers', 'customers_onec_guid_unique', 1);
        $this->assertForeignKeyCountOnColumn('customers', 'account_manager_id', 1);
        $this->assertForeignKeyCountOnColumn('customers', 'backup_manager_id', 1);
        $this->assertCompoundForeignKeyCount('customers', ['workspace_id', 'default_price_list_id'], 1);
    }

    private function assertContractorsTableSchema(): void
    {
        $this->assertNamedForeignKeyCount('contractors', 'contractors_workspace_default_price_list_fk', 1);
        $this->assertNamedForeignKeyCount('contractors', 'contractors_account_manager_id_foreign', 1);
        $this->assertNamedForeignKeyCount('contractors', 'contractors_backup_manager_id_foreign', 1);
        $this->assertNamedForeignKeyCount('contractors', 'contractors_workspace_id_foreign', 1);
        $this->assertNamedUniqueIndexCount('contractors', 'contractors_login_unique', 1);
        $this->assertNamedUniqueIndexCount('contractors', 'contractors_onec_guid_unique', 1);
        $this->assertForeignKeyCountOnColumn('contractors', 'account_manager_id', 1);
        $this->assertForeignKeyCountOnColumn('contractors', 'backup_manager_id', 1);
        $this->assertCompoundForeignKeyCount('contractors', ['workspace_id', 'default_price_list_id'], 1);
    }

    private function assertSchemaContainsNoContractorArtifacts(): void
    {
        $schema = DB::getDatabaseName();

        $this->assertEmpty(
            DB::select(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?',
                [$schema, '%contractor%']
            ),
            'information_schema.TABLES must not contain contractor'
        );

        $this->assertEmpty(
            DB::select(
                'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME LIKE ?',
                [$schema, '%contractor%']
            ),
            'information_schema.COLUMNS must not contain contractor'
        );

        $this->assertEmpty(
            DB::select(
                'SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND CONSTRAINT_NAME LIKE ?',
                [$schema, '%contractor%']
            ),
            'information_schema.TABLE_CONSTRAINTS must not contain contractor'
        );

        $this->assertEmpty(
            DB::select(
                'SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND INDEX_NAME LIKE ?',
                [$schema, '%contractor%']
            ),
            'information_schema.STATISTICS must not contain contractor'
        );
    }

    private function assertSchemaContainsNoCustomerConstraintArtifacts(): void
    {
        $schema = DB::getDatabaseName();

        $this->assertEmpty(
            DB::select(
                'SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND CONSTRAINT_NAME LIKE ?',
                [$schema, 'customers_%']
            ),
            'information_schema.TABLE_CONSTRAINTS must not contain customers_ after down()'
        );

        $this->assertEmpty(
            DB::select(
                'SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND INDEX_NAME LIKE ?',
                [$schema, 'customers_%']
            ),
            'information_schema.STATISTICS must not contain customers_ after down()'
        );
    }

    private function assertSyncLogTypeEnumContains(string $value): void
    {
        $columnType = $this->syncLogTypeColumnType();

        $this->assertStringContainsString(
            "'{$value}'",
            $columnType,
            "sync_logs.type ENUM must contain '{$value}', got: {$columnType}"
        );
    }

    private function assertSyncLogTypeEnumDoesNotContain(string $value): void
    {
        $columnType = $this->syncLogTypeColumnType();

        $this->assertStringNotContainsString(
            "'{$value}'",
            $columnType,
            "sync_logs.type ENUM must not contain '{$value}', got: {$columnType}"
        );
    }

    private function syncLogTypeColumnType(): string
    {
        return (string) DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'sync_logs')
            ->where('COLUMN_NAME', 'type')
            ->value('COLUMN_TYPE');
    }

    private function assertNamedForeignKeyCount(string $table, string $constraintName, int $expected): void
    {
        $count = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraintName)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->count();

        $this->assertSame($expected, $count, "Expected {$expected} FK named {$constraintName} on {$table}");
    }

    private function assertNamedUniqueIndexCount(string $table, string $indexName, int $expected): void
    {
        $count = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $indexName)
            ->where('NON_UNIQUE', 0)
            ->distinct('INDEX_NAME')
            ->count('INDEX_NAME');

        $this->assertSame($expected, $count, "Expected {$expected} unique index named {$indexName} on {$table}");
    }

    /**
     * Counts ALL foreign key constraints that reference this column, including
     * as a member of a compound (multi-column) foreign key — not just
     * single-column FKs. A column that's both a single-column FK and part of a
     * compound FK will correctly count as 2. Use this only for columns you've
     * verified have no compound-FK overlap; otherwise prefer
     * assertNamedForeignKeyCount() / assertCompoundForeignKeyCount() for
     * unambiguous, name-specific checks.
     */
    private function assertForeignKeyCountOnColumn(string $table, string $column, int $expected): void
    {
        $count = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->distinct('CONSTRAINT_NAME')
            ->count('CONSTRAINT_NAME');

        $this->assertSame($expected, $count, "Expected {$expected} FK on {$table}.{$column}");
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertCompoundForeignKeyCount(string $table, array $columns, int $expected): void
    {
        $schema = DB::getDatabaseName();

        $constraints = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $schema)
            ->where('TABLE_NAME', $table)
            ->whereIn('COLUMN_NAME', $columns)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->unique()
            ->values();

        $matching = $constraints->filter(function (string $constraint) use ($schema, $table, $columns) {
            $constraintColumns = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', $schema)
                ->where('TABLE_NAME', $table)
                ->where('CONSTRAINT_NAME', $constraint)
                ->orderBy('ORDINAL_POSITION')
                ->pluck('COLUMN_NAME')
                ->values()
                ->all();

            return $constraintColumns === $columns;
        });

        $this->assertSame(
            $expected,
            $matching->count(),
            'Expected '.$expected.' compound FK on '.implode('+', $columns).' for '.$table
        );
    }
}
