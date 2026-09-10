<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('connector_account_id');
            $table->string('data_domain');
            $table->json('external_context');
            $table->char('external_context_key', 64);
            $table->json('enabled_operations');
            $table->string('operational_state')->default('enabled');
            $table->char('configuration_revision', 64);
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'sc_ws_id_unique');
            $table->unique(
                ['connector_account_id', 'data_domain', 'external_context_key'],
                'sc_account_domain_context_unique',
            );
        });

        Schema::table('sync_configurations', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'sc_ws_account_fk',
            )->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('sync_configurations', function (Blueprint $table) {
                $table->dropForeign('sc_ws_account_fk');
            });
        } else {
            Schema::table('sync_configurations', function (Blueprint $table) {
                $table->dropForeign(['workspace_id', 'connector_account_id']);
            });
        }

        Schema::dropIfExists('sync_configurations');
    }
};
