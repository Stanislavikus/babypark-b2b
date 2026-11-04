<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->decimal('default_vat_rate', 5, 2)->nullable();
            $table->string('default_price_display_mode')->default('tax_inclusive_primary');
        });

        DB::table('workspaces')
            ->whereNull('default_vat_rate')
            ->update(['default_vat_rate' => config('pricing.default_vat_rate', 20)]);

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE workspaces MODIFY default_vat_rate DECIMAL(5,2) NOT NULL');
        } else {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->decimal('default_vat_rate', 5, 2)->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['default_vat_rate', 'default_price_display_mode']);
        });
    }
};
