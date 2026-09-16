<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_catalog_scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('connector_account_id');
            $table->string('data_domain', 64);
            $table->json('target_context');
            $table->string('status', 32);
            $table->unsignedBigInteger('expected_item_count')->nullable();
            $table->unsignedBigInteger('received_item_count')->default(0);
            $table->string('failure_code')->nullable();
            $table->text('failure_detail')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'rcs_ws_id_unique');
            $table->unique(
                ['workspace_id', 'connector_account_id', 'data_domain', 'id'],
                'rcs_owner_id_unique',
            );
            $table->index(
                ['workspace_id', 'connector_account_id', 'data_domain', 'started_at'],
                'rcs_owner_started_idx',
            );
        });

        Schema::table('remote_catalog_scans', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'rcs_ws_account_fk',
            )->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();
        });

        Schema::create('remote_catalog_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('connector_account_id');
            $table->string('data_domain', 64);
            $table->uuid('scan_id');
            $table->uuid('previous_snapshot_id')->nullable();
            $table->json('target_context');
            $table->unsignedBigInteger('item_count')->default(0);
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique('scan_id', 'rcsnap_scan_unique');
            $table->unique(['workspace_id', 'id'], 'rcsnap_ws_id_unique');
            $table->unique(
                ['workspace_id', 'connector_account_id', 'data_domain', 'id'],
                'rcsnap_owner_id_unique',
            );
            $table->index(
                ['workspace_id', 'connector_account_id', 'data_domain', 'published_at'],
                'rcsnap_owner_published_idx',
            );
        });

        Schema::table('remote_catalog_snapshots', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'rcsnap_ws_account_fk',
            )->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'connector_account_id', 'data_domain', 'scan_id'],
                'rcsnap_owner_scan_fk',
            )->references(['workspace_id', 'connector_account_id', 'data_domain', 'id'])
                ->on('remote_catalog_scans')
                ->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'connector_account_id', 'data_domain', 'previous_snapshot_id'],
                'rcsnap_owner_previous_fk',
            )->references(['workspace_id', 'connector_account_id', 'data_domain', 'id'])
                ->on('remote_catalog_snapshots')
                ->restrictOnDelete();
        });

        Schema::create('remote_catalog_snapshot_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('snapshot_id');
            $table->string('remote_identifier', 255);
            $table->string('sku', 255)->nullable();
            $table->text('name')->nullable();
            $table->string('remote_type', 100)->nullable();
            $table->string('remote_status', 100)->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->text('thumbnail_locator')->nullable();
            $table->json('storefront_locator')->nullable();
            $table->timestamps();

            $table->unique(['snapshot_id', 'remote_identifier'], 'rcsi_snapshot_remote_unique');
            $table->index(['snapshot_id', 'sku'], 'rcsi_snapshot_sku_idx');
        });

        Schema::table('remote_catalog_snapshot_items', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'snapshot_id'],
                'rcsi_ws_snapshot_fk',
            )->references(['workspace_id', 'id'])->on('remote_catalog_snapshots')->restrictOnDelete();
        });

        Schema::create('remote_catalog_current_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('connector_account_id');
            $table->string('data_domain', 64);
            $table->uuid('snapshot_id');
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'connector_account_id', 'data_domain'],
                'rccs_owner_unique',
            );
        });

        Schema::table('remote_catalog_current_snapshots', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'rccs_ws_account_fk',
            )->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'connector_account_id', 'data_domain', 'snapshot_id'],
                'rccs_owner_snapshot_fk',
            )->references(['workspace_id', 'connector_account_id', 'data_domain', 'id'])
                ->on('remote_catalog_snapshots')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_catalog_current_snapshots');
        Schema::dropIfExists('remote_catalog_snapshot_items');
        Schema::dropIfExists('remote_catalog_snapshots');
        Schema::dropIfExists('remote_catalog_scans');
    }
};
