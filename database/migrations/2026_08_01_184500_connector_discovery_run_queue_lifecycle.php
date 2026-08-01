<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_discovery_runs', function (Blueprint $table) {
            $table->unsignedTinyInteger('execution_attempts')->default(0)->after('status');
            $table->timestamp('retry_until_at')->nullable()->after('execution_attempts');
            $table->timestamp('next_attempt_at')->nullable()->after('retry_until_at');

            $table->index(
                ['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'status', 'retry_until_at'],
                'cdr_active_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('connector_discovery_runs', function (Blueprint $table) {
                $table->dropForeign('cdr_ws_account_fk');
                $table->dropForeign('connector_discovery_runs_workspace_id_foreign');
            });
        }

        Schema::table('connector_discovery_runs', function (Blueprint $table) {
            $table->dropIndex('cdr_active_lookup_idx');
            $table->dropColumn(['execution_attempts', 'retry_until_at', 'next_attempt_at']);
        });

        if ($driver === 'mysql') {
            Schema::table('connector_discovery_runs', function (Blueprint $table) {
                $table->foreign('workspace_id')
                    ->references('id')
                    ->on('workspaces');
                $table->foreign(
                    ['workspace_id', 'connector_account_id'],
                    'cdr_ws_account_fk',
                )->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();
            });
        }
    }
};
