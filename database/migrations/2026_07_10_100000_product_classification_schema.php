<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! Schema::hasColumn('products', 'merchant_type')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('merchant_type')->nullable()->after('brand');
            });
        }

        if (! $this->indexExists('products', 'products_workspace_id_id_unique')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unique(['workspace_id', 'id'], 'products_workspace_id_id_unique');
            });
        }

        if (! Schema::hasTable('tags')) {
            Schema::create('tags', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->string('name');
                $table->timestamps();

                $table->unique(['workspace_id', 'name'], 'tags_workspace_name_unique');
                $table->unique(['workspace_id', 'id'], 'tags_workspace_id_id_unique');
            });
        }

        if (! Schema::hasTable('product_tag')) {
            Schema::create('product_tag', function (Blueprint $table) {
                $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignUuid('tag_id')->constrained('tags')->cascadeOnDelete();

                $table->primary(['workspace_id', 'product_id', 'tag_id'], 'product_tag_primary');
            });

            if ($driver === 'mysql') {
                Schema::table('product_tag', function (Blueprint $table) {
                    $table->foreign(
                        ['workspace_id', 'product_id'],
                        'product_tag_workspace_product_fk'
                    )->references(['workspace_id', 'id'])->on('products')->cascadeOnDelete();

                    $table->foreign(
                        ['workspace_id', 'tag_id'],
                        'product_tag_workspace_tag_fk'
                    )->references(['workspace_id', 'id'])->on('tags')->cascadeOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (Schema::hasTable('product_tag')) {
            if ($driver === 'mysql') {
                Schema::table('product_tag', function (Blueprint $table) {
                    $table->dropForeign('product_tag_workspace_product_fk');
                    $table->dropForeign('product_tag_workspace_tag_fk');
                });
            }

            Schema::dropIfExists('product_tag');
        }

        Schema::dropIfExists('tags');

        if ($this->indexExists('products', 'products_workspace_id_id_unique')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropUnique('products_workspace_id_id_unique');
            });
        }

        if (Schema::hasColumn('products', 'merchant_type')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('merchant_type');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $result = DB::selectOne(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
                [$table, $indexName]
            );

            return $result !== null;
        }

        $database = Schema::getConnection()->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
