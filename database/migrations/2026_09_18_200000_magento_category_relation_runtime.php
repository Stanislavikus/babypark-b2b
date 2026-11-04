<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->indexExists('categories', 'categories_workspace_id_id_unique')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->unique(['workspace_id', 'id'], 'categories_workspace_id_id_unique');
            });
        }

        if (! $this->indexExists('external_record_links', 'erl_ws_account_id_unique')) {
            Schema::table('external_record_links', function (Blueprint $table): void {
                $table->unique(
                    ['workspace_id', 'connector_account_id', 'id'],
                    'erl_ws_account_id_unique',
                );
            });
        }

        Schema::create('connector_category_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('connector_account_id');
            $table->unsignedBigInteger('category_id');
            $table->string('external_category_id', 255);
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'connector_account_id', 'category_id'],
                'ccm_ws_account_category_unique',
            );
            $table->unique(['workspace_id', 'id'], 'ccm_ws_id_unique');
        });

        Schema::table('connector_category_mappings', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'ccm_ws_account_fk',
            )->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'category_id'],
                'ccm_ws_category_fk',
            )->references(['workspace_id', 'id'])->on('categories')->restrictOnDelete();
        });

        Schema::create('adobe_product_category_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('connector_account_id');
            $table->uuid('external_record_link_id');
            $table->string('external_category_id', 255);
            $table->string('state', 32);
            $table->string('anchor_entity_id', 255)->nullable();
            $table->timestamp('attempt_dispatched_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'connector_account_id', 'external_record_link_id', 'external_category_id'],
                'apca_relation_unique',
            );
            $table->index(
                ['workspace_id', 'connector_account_id', 'state'],
                'apca_ws_account_state_index',
            );
        });

        Schema::table('adobe_product_category_assignments', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'apca_ws_account_fk',
            )->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'connector_account_id', 'external_record_link_id'],
                'apca_ws_account_erl_fk',
            )->references(['workspace_id', 'connector_account_id', 'id'])
                ->on('external_record_links')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adobe_product_category_assignments');
        Schema::dropIfExists('connector_category_mappings');

        if ($this->indexExists('external_record_links', 'erl_ws_account_id_unique')) {
            Schema::table('external_record_links', function (Blueprint $table): void {
                $table->dropUnique('erl_ws_account_id_unique');
            });
        }

        if ($this->indexExists('categories', 'categories_workspace_id_id_unique')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->dropUnique('categories_workspace_id_id_unique');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            foreach ($connection->select("PRAGMA index_list('{$table}')") as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'mysql') {
            return $connection->select(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$connection->getDatabaseName(), $table, $indexName],
            ) !== [];
        }

        return false;
    }
};
