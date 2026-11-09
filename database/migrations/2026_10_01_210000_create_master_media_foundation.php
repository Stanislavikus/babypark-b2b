<?php

use App\Support\Migrations\MasterMediaLegacyBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->uuid('parent_media_asset_id')->nullable();
            $table->string('asset_type', 32)->default('image');
            $table->string('storage_disk', 64)->nullable();
            $table->string('storage_path', 1024)->nullable();
            $table->text('source_url')->nullable();
            $table->string('original_filename', 512)->nullable();
            $table->string('mime_type', 255)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->unsignedInteger('width_px')->nullable();
            $table->unsignedInteger('height_px')->nullable();
            $table->string('diagnosis_status', 32)->default('pending');
            $table->json('diagnosis_json')->nullable();
            $table->json('provenance_json')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'media_assets_workspace_id_id_unique');
            $table->unique(['workspace_id', 'content_sha256'], 'media_assets_workspace_hash_unique');
            $table->index(['workspace_id', 'asset_type'], 'media_assets_workspace_type_idx');
            $table->foreign(['workspace_id', 'parent_media_asset_id'], 'media_assets_workspace_parent_fk')
                ->references(['workspace_id', 'id'])->on('media_assets')->restrictOnDelete();
        });

        Schema::create('product_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->uuid('media_asset_id');
            $table->string('role', 32)->default('gallery');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('locale', 32)->nullable();
            $table->unsignedTinyInteger('primary_marker')->nullable()->storedAs(
                "CASE WHEN role = 'primary' THEN 1 ELSE NULL END"
            );
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'product_media_workspace_id_id_unique');
            $table->unique(['workspace_id', 'product_id', 'sort_order'], 'product_media_product_order_unique');
            $table->unique(['workspace_id', 'product_id', 'primary_marker'], 'product_media_one_primary_unique');
            $table->foreign(['workspace_id', 'product_id'], 'product_media_workspace_product_fk')
                ->references(['workspace_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'media_asset_id'], 'product_media_workspace_asset_fk')
                ->references(['workspace_id', 'id'])->on('media_assets')->restrictOnDelete();
        });

        Schema::create('variant_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id');
            $table->uuid('media_asset_id');
            $table->string('role', 32)->default('gallery');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('locale', 32)->nullable();
            $table->unsignedTinyInteger('primary_marker')->nullable()->storedAs(
                "CASE WHEN role = 'primary' THEN 1 ELSE NULL END"
            );
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'variant_media_workspace_id_id_unique');
            $table->unique(['workspace_id', 'variant_id', 'sort_order'], 'variant_media_variant_order_unique');
            $table->unique(['workspace_id', 'variant_id', 'primary_marker'], 'variant_media_one_primary_unique');
            $table->foreign(['workspace_id', 'variant_id'], 'variant_media_workspace_variant_fk')
                ->references(['workspace_id', 'id'])->on('product_variants')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'media_asset_id'], 'variant_media_workspace_asset_fk')
                ->references(['workspace_id', 'id'])->on('media_assets')->restrictOnDelete();
        });

        (new MasterMediaLegacyBackfill)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_media');
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('media_assets');
    }
};
