<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('field_mappings', 'workspace_id')) {
            throw new RuntimeException('field_mappings table must exist before field_option_mappings migration.');
        }

        Schema::table('field_mappings', function (Blueprint $table) {
            if (! $this->indexExists('field_mappings', 'fm_ws_id_unique')) {
                $table->unique(['workspace_id', 'id'], 'fm_ws_id_unique');
            }
        });

        Schema::create('field_option_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('field_mapping_id');
            $table->string('internal_option_key');
            $table->string('external_option_value');
            $table->timestamps();

            $table->unique(['field_mapping_id', 'internal_option_key'], 'fom_mapping_internal_option_unique');
        });

        Schema::table('field_option_mappings', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'field_mapping_id'],
                'fom_ws_mapping_fk',
            )->references(['workspace_id', 'id'])->on('field_mappings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('field_option_mappings', function (Blueprint $table) {
                $table->dropForeign('fom_ws_mapping_fk');
            });
        } else {
            Schema::table('field_option_mappings', function (Blueprint $table) {
                $table->dropForeign(['workspace_id', 'field_mapping_id']);
            });
        }

        Schema::dropIfExists('field_option_mappings');

        if ($this->indexExists('field_mappings', 'fm_ws_id_unique')) {
            Schema::table('field_mappings', function (Blueprint $table) {
                $table->dropUnique('fm_ws_id_unique');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'mysql') {
            $database = $connection->getDatabaseName();
            $result = $connection->select(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$database, $table, $indexName],
            );

            return $result !== [];
        }

        return false;
    }
};
