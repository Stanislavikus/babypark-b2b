<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_catalog_snapshot_items', function (Blueprint $table) {
            $table->unsignedBigInteger('external_attribute_set_id')->nullable()->after('remote_status');
            $table->string('provider_brand_field_key', 255)->nullable()->after('thumbnail_locator');
            $table->string('provider_brand_value', 255)->nullable()->after('provider_brand_field_key');
            $table->string('provider_brand_label', 255)->nullable()->after('provider_brand_value');

            $table->unique(['workspace_id', 'id'], 'rcsi_workspace_id_unique');
            $table->index(['snapshot_id', 'external_attribute_set_id'], 'rcsi_snapshot_attribute_set_idx');
            $table->index(['snapshot_id', 'provider_brand_label'], 'rcsi_snapshot_brand_label_idx');
        });

        Schema::create('remote_catalog_snapshot_item_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('workspace_id');
            $table->unsignedBigInteger('snapshot_item_id');
            $table->string('external_category_id', 255);
            $table->text('category_path')->nullable();
            $table->integer('position')->nullable();
            $table->timestamps();

            $table->unique(
                ['snapshot_item_id', 'external_category_id'],
                'rcsic_item_category_unique',
            );
            $table->index(
                ['workspace_id', 'external_category_id', 'snapshot_item_id'],
                'rcsic_workspace_category_idx',
            );

            $table->foreign('workspace_id', 'rcsic_workspace_fk')
                ->references('id')->on('workspaces')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'snapshot_item_id'],
                'rcsic_workspace_item_fk',
            )->references(['workspace_id', 'id'])
                ->on('remote_catalog_snapshot_items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_catalog_snapshot_item_categories');

        Schema::table('remote_catalog_snapshot_items', function (Blueprint $table) {
            $table->dropIndex('rcsi_snapshot_brand_label_idx');
            $table->dropIndex('rcsi_snapshot_attribute_set_idx');
            $table->dropUnique('rcsi_workspace_id_unique');
            $table->dropColumn([
                'external_attribute_set_id',
                'provider_brand_field_key',
                'provider_brand_value',
                'provider_brand_label',
            ]);
        });
    }
};
