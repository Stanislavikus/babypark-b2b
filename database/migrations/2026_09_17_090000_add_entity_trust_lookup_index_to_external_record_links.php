<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'erl_ws_account_trust_discriminator_idx';

    public function up(): void
    {
        Schema::table('external_record_links', function (Blueprint $table) {
            $table->index(
                ['workspace_id', 'connector_account_id', 'trust_origin', 'external_record_discriminator'],
                self::INDEX,
            );
        });
    }

    public function down(): void
    {
        Schema::table('external_record_links', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
