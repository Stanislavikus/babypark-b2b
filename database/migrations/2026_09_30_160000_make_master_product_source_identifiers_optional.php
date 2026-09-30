<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->uuid('onec_guid')->nullable()->change();
            $table->string('sku')->nullable()->change();
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->uuid('onec_guid')->nullable()->change();
            $table->string('sku')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (
            DB::table('products')->whereNull('onec_guid')->exists()
            || DB::table('products')->whereNull('sku')->exists()
            || DB::table('product_variants')->whereNull('onec_guid')->exists()
            || DB::table('product_variants')->whereNull('sku')->exists()
        ) {
            throw new RuntimeException(
                'Cannot restore legacy non-null source identifiers while source-neutral product drafts exist.'
            );
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->uuid('onec_guid')->nullable(false)->change();
            $table->string('sku')->nullable(false)->change();
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->uuid('onec_guid')->nullable(false)->change();
            $table->string('sku')->nullable(false)->change();
        });
    }
};
