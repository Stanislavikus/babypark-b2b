<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('lifecycle_status', 16)
                ->default('active')
                ->after('is_active')
                ->index();
        });

        DB::table('products')
            ->where('is_active', false)
            ->update(['lifecycle_status' => 'archived']);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['lifecycle_status']);
            $table->dropColumn('lifecycle_status');
        });
    }
};
