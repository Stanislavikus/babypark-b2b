<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_connection_checks', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->change();
            $table->unsignedTinyInteger('execution_attempts')->default(0)->after('status');
            $table->timestamp('retry_until_at')->nullable()->after('execution_attempts');
            $table->timestamp('next_attempt_at')->nullable()->after('retry_until_at');

            $table->index(
                ['workspace_id', 'connector_account_id', 'status', 'retry_until_at'],
                'connector_checks_active_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        DB::table('connector_connection_checks')
            ->whereIn('status', ['queued', 'running'])
            ->whereNull('error_code')
            ->update([
                'error_code' => 'connection_check_job_failed',
                'cause_category' => 'unknown',
                'actionability' => 'support_required',
                'user_message_key' => 'connectors.errors.connection_check_failed',
                'technical_summary' => 'queue_job_failed',
            ]);

        DB::table('connector_connection_checks')
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'started_at' => DB::raw('COALESCE(started_at, created_at)'),
                'finished_at' => now(),
            ]);

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('connector_connection_checks', function (Blueprint $table) {
                $table->dropForeign('ccc_ws_account_fk');
            });
        }

        Schema::table('connector_connection_checks', function (Blueprint $table) {
            $table->dropIndex('connector_checks_active_lookup_idx');
            $table->dropColumn(['execution_attempts', 'retry_until_at', 'next_attempt_at']);
            $table->timestamp('started_at')->nullable(false)->change();
        });

        if ($driver === 'mysql') {
            Schema::table('connector_connection_checks', function (Blueprint $table) {
                $table->foreign(
                    ['workspace_id', 'connector_account_id'],
                    'ccc_ws_account_fk',
                )->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();
            });
        }
    }
};
