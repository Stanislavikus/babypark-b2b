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
            $table->foreignUuid('workspace_id')->nullable()->after('id')->constrained('workspaces');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')->nullable()->after('id')->constrained('workspaces');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')->nullable()->after('id')->constrained('workspaces');
        });

        $defaultWorkspaceId = DB::table('workspaces')->where('is_default', true)->value('id');

        if ($defaultWorkspaceId === null) {
            throw new RuntimeException('No default workspace found for workspace_id backfill.');
        }

        DB::table('products')->update(['workspace_id' => $defaultWorkspaceId]);
        DB::table('categories')->update(['workspace_id' => $defaultWorkspaceId]);

        $variants = DB::table('product_variants')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->select('product_variants.id', 'products.workspace_id')
            ->get();

        foreach ($variants as $variant) {
            DB::table('product_variants')
                ->where('id', $variant->id)
                ->update(['workspace_id' => $variant->workspace_id]);
        }

        $orphanedCount = DB::table('product_variants')->whereNull('workspace_id')->count();

        if ($orphanedCount > 0) {
            DB::table('product_variants')
                ->whereNull('workspace_id')
                ->update(['workspace_id' => $defaultWorkspaceId]);
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products MODIFY workspace_id CHAR(36) NOT NULL');
            DB::statement('ALTER TABLE categories MODIFY workspace_id CHAR(36) NOT NULL');
            DB::statement('ALTER TABLE product_variants MODIFY workspace_id CHAR(36) NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropColumn('workspace_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropColumn('workspace_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropColumn('workspace_id');
        });
    }
};
