<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->uuid('source_preview_run_id')->nullable()->after('configuration_snapshot');
            $table->index(['workspace_id', 'source_preview_run_id'], 'sr_ws_source_preview_idx');
            $table->foreign(['workspace_id', 'source_preview_run_id'], 'sr_ws_source_preview_fk')
                ->references(['workspace_id', 'id'])->on('sync_runs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('sync_runs', function (Blueprint $table) use ($driver) {
            if ($driver === 'mysql') {
                $table->dropForeign('sr_ws_source_preview_fk');
            } else {
                $table->dropForeign(['workspace_id', 'source_preview_run_id']);
            }
            $table->dropIndex('sr_ws_source_preview_idx');
            $table->dropColumn('source_preview_run_id');
        });
    }
};
