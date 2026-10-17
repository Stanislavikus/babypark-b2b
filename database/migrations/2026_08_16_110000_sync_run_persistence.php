<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('sync_configuration_id');
            $table->char('configuration_revision', 64);
            $table->string('mode');
            $table->string('semantic_operation');
            $table->string('status');
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('configuration_snapshot');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'sr_ws_id_unique');
            $table->index(
                ['workspace_id', 'sync_configuration_id', 'status'],
                'sr_ws_config_status_idx',
            );
        });

        Schema::table('sync_runs', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'sync_configuration_id'],
                'sr_ws_config_fk',
            )->references(['workspace_id', 'id'])->on('sync_configurations')->restrictOnDelete();
        });

        Schema::create('sync_run_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('sync_run_id');
            $table->unsignedBigInteger('product_id');
            $table->string('outcome');
            $table->json('findings');
            $table->timestamps();

            $table->unique(['sync_run_id', 'product_id'], 'sri_run_product_unique');
            $table->unique(['workspace_id', 'id'], 'sri_ws_id_unique');
        });

        Schema::table('sync_run_items', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'sync_run_id'],
                'sri_ws_run_fk',
            )->references(['workspace_id', 'id'])->on('sync_runs')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'product_id'],
                'sri_ws_product_fk',
            )->references(['workspace_id', 'id'])->on('products')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('sync_run_items', function (Blueprint $table) {
                $table->dropForeign('sri_ws_product_fk');
                $table->dropForeign('sri_ws_run_fk');
            });
        } else {
            Schema::table('sync_run_items', function (Blueprint $table) {
                $table->dropForeign(['workspace_id', 'product_id']);
                $table->dropForeign(['workspace_id', 'sync_run_id']);
            });
        }

        Schema::dropIfExists('sync_run_items');

        if ($driver === 'mysql') {
            Schema::table('sync_runs', function (Blueprint $table) {
                $table->dropForeign('sr_ws_config_fk');
            });
        } else {
            Schema::table('sync_runs', function (Blueprint $table) {
                $table->dropForeign(['workspace_id', 'sync_configuration_id']);
            });
        }

        Schema::dropIfExists('sync_runs');
    }
};
