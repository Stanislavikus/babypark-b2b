<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('price', 15, 2);
            $table->decimal('price_with_vat', 15, 2);
            $table->decimal('vat_rate', 5, 2)->default(20);
            $table->decimal('recommended_retail_price', 15, 2)->nullable();
            $table->unsignedInteger('min_quantity')->default(1);
            $table->string('currency', 3)->default('UAH');
            $table->timestamp('updated_at')->useCurrent();
            $table->unique(['contractor_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
