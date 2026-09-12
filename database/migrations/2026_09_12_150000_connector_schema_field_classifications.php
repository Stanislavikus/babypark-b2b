<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_schema_field_classifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('connector_account_id');
            $table->foreignUuid('connector_schema_source_id')->constrained('connector_schema_sources')->restrictOnDelete();
            $table->string('external_field_key');
            $table->uuid('latest_snapshot_field_id');
            $table->string('disposition', 64);
            $table->string('behavior_class', 96)->nullable();
            $table->json('behavior_signature')->nullable();
            $table->string('runtime_owner_hint', 64)->nullable();
            $table->string('canonical_code')->nullable();
            $table->string('mapping_strategy', 64);
            $table->string('classifier_version', 96);
            $table->string('reason_code', 128);
            $table->char('classified_canonical_hash', 64);
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'external_field_key'], 'csfc_identity_unique');
            $table->unique(['workspace_id', 'id'], 'csfc_ws_id_unique');
            $table->index(['connector_account_id', 'connector_schema_source_id', 'disposition'], 'csfc_account_source_disp_idx');
        });

        Schema::table('connector_schema_field_classifications', function (Blueprint $table) {
            $table->foreign(['workspace_id', 'connector_account_id'], 'csfc_ws_account_fk')
                ->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'latest_snapshot_field_id'], 'csfc_ws_field_fk')
                ->references(['workspace_id', 'id'])->on('connector_schema_snapshot_fields')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_schema_field_classifications');
    }
};
