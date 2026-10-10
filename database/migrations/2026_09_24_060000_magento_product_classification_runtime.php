<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adobe_product_attribute_sets', function (Blueprint $table): void {
            $table->unique(
                ['workspace_id', 'connector_account_id', 'id'],
                'apas_ws_account_id_unique',
            );
        });

        Schema::create('adobe_product_type_attribute_set_defaults', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('connector_account_id');
            $table->uuid('product_type_id');
            $table->uuid('adobe_product_attribute_set_id');
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'connector_account_id', 'product_type_id'],
                'aptasd_ws_account_type_unique',
            );

            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'aptasd_ws_account_fk',
            )->references(['workspace_id', 'id'])
                ->on('connector_accounts')
                ->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'product_type_id'],
                'aptasd_ws_type_fk',
            )->references(['workspace_id', 'id'])
                ->on('product_types')
                ->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'connector_account_id', 'adobe_product_attribute_set_id'],
                'aptasd_ws_account_set_fk',
            )->references(['workspace_id', 'connector_account_id', 'id'])
                ->on('adobe_product_attribute_sets')
                ->restrictOnDelete();
        });

        Schema::create('adobe_product_attribute_set_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('connector_account_id');
            $table->unsignedBigInteger('product_id');
            $table->uuid('adobe_product_attribute_set_id');
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'connector_account_id', 'product_id'],
                'apaso_ws_account_product_unique',
            );

            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'apaso_ws_account_fk',
            )->references(['workspace_id', 'id'])
                ->on('connector_accounts')
                ->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'product_id'],
                'apaso_ws_product_fk',
            )->references(['workspace_id', 'id'])
                ->on('products')
                ->cascadeOnDelete();

            $table->foreign(
                ['workspace_id', 'connector_account_id', 'adobe_product_attribute_set_id'],
                'apaso_ws_account_set_fk',
            )->references(['workspace_id', 'connector_account_id', 'id'])
                ->on('adobe_product_attribute_sets')
                ->restrictOnDelete();
        });

        Schema::create('adobe_product_category_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('connector_account_id');
            $table->unsignedBigInteger('product_id');
            $table->string('external_category_id', 255);
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'connector_account_id', 'product_id', 'external_category_id'],
                'apco_ws_account_product_category_unique',
            );

            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'apco_ws_account_fk',
            )->references(['workspace_id', 'id'])
                ->on('connector_accounts')
                ->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'product_id'],
                'apco_ws_product_fk',
            )->references(['workspace_id', 'id'])
                ->on('products')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adobe_product_category_overrides');
        Schema::dropIfExists('adobe_product_attribute_set_overrides');
        Schema::dropIfExists('adobe_product_type_attribute_set_defaults');

        Schema::table('adobe_product_attribute_sets', function (Blueprint $table): void {
            $table->dropUnique('apas_ws_account_id_unique');
        });
    }
};
