<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('products')->whereNull('product_type_id')->exists()) {
            throw new RuntimeException('Cannot enforce products.product_type_id NOT NULL while untyped products remain.');
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->uuid('product_type_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->uuid('product_type_id')->nullable()->change();
        });
    }
};
