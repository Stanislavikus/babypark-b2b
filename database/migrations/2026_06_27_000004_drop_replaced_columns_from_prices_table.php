<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * These columns now live in product_prices (contract_price / list_price) and are
     * read exclusively through PriceResolver. Drop them so no parallel source remains.
     */
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            if (Schema::hasColumn('prices', 'price_with_vat')) {
                $table->dropColumn('price_with_vat');
            }
            if (Schema::hasColumn('prices', 'recommended_retail_price')) {
                $table->dropColumn('recommended_retail_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            if (! Schema::hasColumn('prices', 'price_with_vat')) {
                $table->decimal('price_with_vat', 15, 2)->nullable()->after('price');
            }
            if (! Schema::hasColumn('prices', 'recommended_retail_price')) {
                $table->decimal('recommended_retail_price', 15, 2)->nullable()->after('vat_rate');
            }
        });
    }
};
