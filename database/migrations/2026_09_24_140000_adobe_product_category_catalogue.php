<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adobe_product_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('connector_account_id');
            $table->string('external_category_id', 255);
            $table->string('parent_external_category_id', 255)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('provider_path', 1024)->nullable();
            $table->text('breadcrumb')->nullable();
            $table->unsignedInteger('level')->default(0);
            $table->integer('position')->nullable();
            $table->boolean('is_active')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'connector_account_id', 'external_category_id'],
                'apc_ws_account_external_unique',
            );
            $table->index(
                ['workspace_id', 'connector_account_id', 'missing_since', 'is_active'],
                'apc_ws_account_current_active_idx',
            );
            $table->index(
                ['workspace_id', 'connector_account_id', 'parent_external_category_id'],
                'apc_ws_account_parent_idx',
            );

            $table->foreign('workspace_id', 'apc_workspace_fk')
                ->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'apc_ws_account_fk',
            )->references(['workspace_id', 'id'])
                ->on('connector_accounts')
                ->restrictOnDelete();
        });

        Schema::create('adobe_product_category_catalogue_states', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('workspace_id');
            $table->uuid('connector_account_id');
            $table->unsignedInteger('category_count');
            $table->json('target_context');
            $table->timestamp('last_successful_synced_at');
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'connector_account_id'],
                'apccs_ws_account_unique',
            );

            $table->foreign('workspace_id', 'apccs_workspace_fk')
                ->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'apccs_ws_account_fk',
            )->references(['workspace_id', 'id'])
                ->on('connector_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adobe_product_category_catalogue_states');
        Schema::dropIfExists('adobe_product_categories');
    }
};
