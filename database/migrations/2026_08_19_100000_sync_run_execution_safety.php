<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->timestamp('queued_abandon_after')->nullable()->after('completed_at');
            $table->timestamp('queue_dispatch_confirmed_at')->nullable()->after('queued_abandon_after');
            $table->timestamp('writer_deadline_at')->nullable()->after('queue_dispatch_confirmed_at');
            $table->timestamp('recoverable_after')->nullable()->after('writer_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropColumn([
                'queued_abandon_after',
                'queue_dispatch_confirmed_at',
                'writer_deadline_at',
                'recoverable_after',
            ]);
        });
    }
};
