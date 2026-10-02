<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adobe_product_attribute_materializations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('connector_account_id');
            $table->uuid('connector_schema_source_id');
            $table->uuid('adobe_product_attribute_lineage_id');
            $table->uuid('field_definition_id');
            $table->timestamp('materialized_at');
            $table->timestamps();

            $table->unique([
                'workspace_id', 'connector_account_id', 'connector_schema_source_id',
                'adobe_product_attribute_lineage_id',
            ], 'apam_lineage_unique');
            $table->unique('field_definition_id', 'apam_definition_unique');
        });

        Schema::table('adobe_product_attribute_materializations', function (Blueprint $table) {
            $table->foreign(['workspace_id', 'connector_account_id'], 'apam_account_fk')
                ->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();
            $table->foreign('connector_schema_source_id', 'apam_source_fk')
                ->references('id')->on('connector_schema_sources')->restrictOnDelete();
            $table->foreign([
                'workspace_id', 'connector_account_id', 'connector_schema_source_id',
                'adobe_product_attribute_lineage_id',
            ], 'apam_lineage_fk')->references([
                'workspace_id', 'connector_account_id', 'connector_schema_source_id', 'id',
            ])->on('adobe_product_attribute_lineages')->restrictOnDelete();
            $table->foreign('field_definition_id', 'apam_definition_fk')
                ->references('id')->on('field_definitions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adobe_product_attribute_materializations');
    }
};
