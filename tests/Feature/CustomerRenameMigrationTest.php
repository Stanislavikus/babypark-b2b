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

        Artisan::call('migrate:rollback', ['--step' => 1]);

        $this->assertTrue(Schema::hasTable('contractors'));
        $this->assertFalse(Schema::hasTable('customers'));
        $this->assertSame(
            $customersBeforeRollback,
            DB::table('contractors')->count(),
            'down() must preserve row count when renaming customers back to contractors'
        );

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
}
