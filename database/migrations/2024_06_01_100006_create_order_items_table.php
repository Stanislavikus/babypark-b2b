<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('sku');
            $table->string('name');
            $table->json('attributes')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('price', 15, 2);
            $table->decimal('price_with_vat', 15, 2);
            $table->decimal('total', 15, 2);
            $table->decimal('manager_price', 15, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
