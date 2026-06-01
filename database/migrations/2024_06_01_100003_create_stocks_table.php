<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('warehouse_name');
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->date('expected_date')->nullable();
            $table->unsignedInteger('expected_quantity')->nullable();
            $table->timestamp('updated_at')->useCurrent();
            $table->unique(['variant_id', 'warehouse_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
