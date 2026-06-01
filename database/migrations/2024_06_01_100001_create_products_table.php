<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('onec_guid')->unique();
            $table->string('sku')->unique();
            $table->string('barcode_ean')->nullable();
            $table->string('barcode_box')->nullable();
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('brand')->nullable();
            $table->string('unit')->default('шт');
            $table->unsignedInteger('min_order_quantity')->default(1);
            $table->unsignedInteger('order_step')->default(1);
            $table->unsignedInteger('package_quantity')->nullable();
            $table->string('package_type')->nullable();
            $table->unsignedInteger('units_per_box')->nullable();
            $table->unsignedInteger('boxes_per_pallet')->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->decimal('weight_netto', 12, 3)->nullable();
            $table->decimal('weight_brutto', 12, 3)->nullable();
            $table->decimal('volume_m3', 12, 6)->nullable();
            $table->unsignedInteger('depth_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->text('description')->nullable();
            $table->json('images')->nullable();
            $table->unsignedBigInteger('rozetka_category_id')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
