<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('code');
            $table->string('data_type');
            $table->string('scope');
            $table->string('value_level');
            $table->string('storage_type');
            $table->string('storage_path')->nullable();
            $table->string('attribute_group');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_sortable')->default(false);
            $table->json('visibility_settings');
            $table->json('validation_rules')->nullable();
            $table->boolean('is_localizable')->default(false);
            $table->boolean('is_multi_value')->default(false);
            $table->string('status')->default('active');
            $table->integer('sort_order')->default(0);
            $table->json('localized_labels');
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE attribute_definitions
                ADD COLUMN workspace_uniqueness_key CHAR(36)
                AS (COALESCE(workspace_id, '00000000-0000-0000-0000-000000000000')) VIRTUAL
            ");

            Schema::table('attribute_definitions', function (Blueprint $table) {
                $table->unique(['workspace_uniqueness_key', 'code'], 'attribute_definitions_workspace_code_unique');
            });
        } else {
            Schema::table('attribute_definitions', function (Blueprint $table) {
                $table->unique('code', 'attribute_definitions_code_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_definitions');
    }
};
