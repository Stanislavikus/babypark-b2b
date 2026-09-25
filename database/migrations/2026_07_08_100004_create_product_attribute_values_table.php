<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('attribute_definition_id')->constrained('attribute_definitions')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_num', 20, 6)->nullable();
            $table->json('value_jsonb')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'product_id', 'attribute_definition_id'], 'product_attr_values_ws_product_attr_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
    }
};
