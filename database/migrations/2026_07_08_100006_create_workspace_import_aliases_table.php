<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_import_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->foreignUuid('attribute_definition_id')->constrained('attribute_definitions')->cascadeOnDelete();
            $table->string('alias_name');
            $table->string('source')->nullable();
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE workspace_import_aliases
                ADD COLUMN source_uniqueness_key VARCHAR(255)
                AS (COALESCE(source, '__NULL_SOURCE__')) VIRTUAL
            ");

            Schema::table('workspace_import_aliases', function (Blueprint $table) {
                $table->unique(
                    ['workspace_id', 'source_uniqueness_key', 'alias_name'],
                    'workspace_import_aliases_ws_source_alias_unique'
                );
            });
        } else {
            Schema::table('workspace_import_aliases', function (Blueprint $table) {
                $table->unique(
                    ['workspace_id', 'alias_name'],
                    'workspace_import_aliases_ws_alias_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_import_aliases');
    }
};
