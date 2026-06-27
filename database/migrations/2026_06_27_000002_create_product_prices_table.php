<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            // Null when the price type is not contractor-specific (list_price, cost_of_goods_sold).
            $table->foreignId('contractor_id')->nullable()->constrained('contractors')->cascadeOnDelete();
            $table->foreignId('price_type_id')->constrained('price_types')->cascadeOnDelete();
            $table->decimal('value', 12, 2);
            $table->string('currency', 3)->default('UAH');
            // Where THIS row's value came from, independent of the type's usual source
            // (e.g. a normally-1С-synced contract_price that got a one-off manual override).
            $table->string('source')->nullable();

            // MySQL (and SQLite) treat NULL values in a unique index as distinct, so a plain
            // unique on (variant_id, contractor_id, price_type_id) would NOT prevent duplicate
            // contractor-less rows (list_price / cost_of_goods_sold, where contractor_id is null).
            // A STORED generated column that coalesces null to a sentinel (0) restores real
            // uniqueness for those rows at the database level on both engines.
            $table->unsignedBigInteger('contractor_key')->storedAs('COALESCE(contractor_id, 0)');

            $table->timestamps();

            $table->index(['variant_id', 'price_type_id']);
            $table->index(['contractor_id', 'price_type_id']);
            $table->unique(['variant_id', 'contractor_key', 'price_type_id'], 'product_prices_variant_contractor_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
