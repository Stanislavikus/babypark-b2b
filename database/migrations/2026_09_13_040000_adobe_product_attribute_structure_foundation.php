<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adobe_product_attribute_lineages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('connector_account_id');
            $table->uuid('connector_schema_source_id');
            $table->unsignedBigInteger('provider_attribute_id');
            $table->string('last_external_field_key');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('missing_since')->nullable();
            $table->string('current_external_field_key')->nullable()->storedAs(
                'CASE WHEN missing_since IS NULL THEN last_external_field_key ELSE NULL END'
            );
            $table->timestamps();

            $table->unique(['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'provider_attribute_id'], 'apal_identity_unique');
            $table->unique(['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'current_external_field_key'], 'apal_current_key_unique');
            $table->unique(['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'id'], 'apal_ctx_id_unique');
        });

        Schema::table('adobe_product_attribute_lineages', function (Blueprint $table) {
            $table->foreign(['workspace_id', 'connector_account_id'], 'apal_account_fk')
                ->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();
            $table->foreign('connector_schema_source_id', 'apal_source_fk')
                ->references('id')->on('connector_schema_sources')->restrictOnDelete();
        });

        Schema::create('adobe_product_attribute_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('connector_account_id');
            $table->uuid('connector_schema_source_id');
            $table->unsignedBigInteger('provider_attribute_set_id');
            $table->string('name');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'provider_attribute_set_id'], 'apas_identity_unique');
            $table->unique(['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'id'], 'apas_ctx_id_unique');
        });

        Schema::table('adobe_product_attribute_sets', function (Blueprint $table) {
            $table->foreign(['workspace_id', 'connector_account_id'], 'apas_account_fk')
                ->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();
            $table->foreign('connector_schema_source_id', 'apas_source_fk')
                ->references('id')->on('connector_schema_sources')->restrictOnDelete();
        });

        Schema::create('adobe_product_attribute_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('connector_account_id');
            $table->uuid('connector_schema_source_id');
            $table->uuid('adobe_product_attribute_set_id');
            $table->unsignedBigInteger('provider_attribute_group_id');
            $table->string('name');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'provider_attribute_group_id'], 'apag_identity_unique');
            $table->unique(['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'id'], 'apag_ctx_id_unique');
        });

        Schema::table('adobe_product_attribute_groups', function (Blueprint $table) {
            $table->foreign(['workspace_id', 'connector_account_id'], 'apag_account_fk')
                ->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();
            $table->foreign('connector_schema_source_id', 'apag_source_fk')
                ->references('id')->on('connector_schema_sources')->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'adobe_product_attribute_set_id'],
                'apag_set_fk',
            )->references(['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'id'])
                ->on('adobe_product_attribute_sets')->restrictOnDelete();
        });

        Schema::create('adobe_product_attribute_set_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('connector_account_id');
            $table->uuid('connector_schema_source_id');
            $table->uuid('adobe_product_attribute_lineage_id');
            $table->uuid('adobe_product_attribute_set_id');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();

            $table->unique([
                'workspace_id', 'connector_account_id', 'connector_schema_source_id',
                'adobe_product_attribute_lineage_id', 'adobe_product_attribute_set_id',
            ], 'apasm_identity_unique');
        });

        Schema::table('adobe_product_attribute_set_memberships', function (Blueprint $table) {
            $table->foreign(['workspace_id', 'connector_account_id'], 'apasm_account_fk')
                ->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();
            $table->foreign('connector_schema_source_id', 'apasm_source_fk')
                ->references('id')->on('connector_schema_sources')->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'adobe_product_attribute_lineage_id'],
                'apasm_lineage_fk',
            )->references(['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'id'])
                ->on('adobe_product_attribute_lineages')->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'adobe_product_attribute_set_id'],
                'apasm_set_fk',
            )->references(['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'id'])
                ->on('adobe_product_attribute_sets')->restrictOnDelete();
        });

        Schema::create('adobe_product_attribute_option_lineages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('connector_account_id');
            $table->uuid('connector_schema_source_id');
            $table->uuid('adobe_product_attribute_lineage_id');
            $table->string('provider_option_id');
            $table->string('default_label')->nullable();
            $table->json('labels_by_store');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();

            $table->unique([
                'workspace_id', 'connector_account_id', 'connector_schema_source_id',
                'adobe_product_attribute_lineage_id', 'provider_option_id',
            ], 'apaol_identity_unique');
        });

        Schema::table('adobe_product_attribute_option_lineages', function (Blueprint $table) {
            $table->foreign(['workspace_id', 'connector_account_id'], 'apaol_account_fk')
                ->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();
            $table->foreign('connector_schema_source_id', 'apaol_source_fk')
                ->references('id')->on('connector_schema_sources')->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'adobe_product_attribute_lineage_id'],
                'apaol_lineage_fk',
            )->references(['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'id'])
                ->on('adobe_product_attribute_lineages')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adobe_product_attribute_option_lineages');
        Schema::dropIfExists('adobe_product_attribute_set_memberships');
        Schema::dropIfExists('adobe_product_attribute_groups');
        Schema::dropIfExists('adobe_product_attribute_sets');
        Schema::dropIfExists('adobe_product_attribute_lineages');
    }
};
