<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->index('workspace_id');
            $table->dropUnique(['onec_guid']);
            $table->unique(['workspace_id', 'onec_guid']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index('workspace_id');
            $table->dropUnique(['onec_guid']);
            $table->dropUnique(['sku']);
            $table->unique(['workspace_id', 'onec_guid']);
            $table->unique(['workspace_id', 'sku']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->index('workspace_id');
            $table->dropUnique(['onec_guid']);
            $table->dropUnique(['sku']);
            $table->unique(['workspace_id', 'onec_guid']);
            $table->unique(['workspace_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'onec_guid']);
            $table->dropUnique(['workspace_id', 'sku']);
            $table->unique('onec_guid');
            $table->unique('sku');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'onec_guid']);
            $table->dropUnique(['workspace_id', 'sku']);
            $table->unique('onec_guid');
            $table->unique('sku');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'onec_guid']);
            $table->unique('onec_guid');
        });
    }
};
