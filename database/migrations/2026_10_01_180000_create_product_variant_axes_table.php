<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variant_axes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->foreignUuid('field_binding_id')->constrained('field_bindings')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'product_variant_axes_workspace_id_id_unique');
            $table->unique(['workspace_id', 'product_id', 'field_binding_id'], 'product_variant_axes_product_binding_unique');
            $table->unique(['workspace_id', 'product_id', 'sort_order'], 'product_variant_axes_product_order_unique');
            $table->foreign(['workspace_id', 'product_id'], 'product_variant_axes_workspace_product_fk')
                ->references(['workspace_id', 'id'])->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_axes');
    }
};
