<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'cost_price')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('cost_price');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'cost_price')) {
            Schema::table('products', function (Blueprint $table) {
                // Non-restorative: original products.cost_price values cannot be recovered;
                // backfilling now happens from product_variants.cost_price, not the reverse.
                $table->decimal('cost_price', 12, 2)->nullable()->after('brand');
            });
        }
    }
};
