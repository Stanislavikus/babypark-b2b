<?php

namespace Tests\Integration\MySql;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkspaceTaxDefaultsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_vat_rate_column_is_not_null_after_migration_on_current_driver(): void
    {
        $column = collect(Schema::getColumns('workspaces'))
            ->firstWhere('name', 'default_vat_rate');

        $this->assertNotNull($column);
        $this->assertFalse((bool) $column['nullable']);

        $nullCount = DB::table('workspaces')->whereNull('default_vat_rate')->count();
        $this->assertSame(0, $nullCount);
    }

    public function test_mysql_driver_can_apply_not_null_change_path(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only migration path verification.');
        }

        Artisan::call('migrate:fresh');

        $column = collect(Schema::getColumns('workspaces'))
            ->firstWhere('name', 'default_vat_rate');

        $this->assertNotNull($column);
        $this->assertFalse((bool) $column['nullable']);
    }
}
