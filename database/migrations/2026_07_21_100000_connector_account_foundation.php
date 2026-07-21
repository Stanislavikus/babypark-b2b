<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->foreignUuid('connector_definition_id')
                ->constrained('connector_definitions')
                ->restrictOnDelete();
            $table->string('name');
            $table->string('auth_profile');
            $table->string('base_url')->nullable();
            $table->string('store_code')->nullable();
            $table->string('tenant_context')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->json('settings')->nullable();
            $table->text('credentials');
            $table->string('connection_status')->default('untested');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_successful_check_at')->nullable();
            $table->timestamp('last_discovery_at')->nullable();
            $table->timestamp('last_successful_discovery_at')->nullable();
            $table->string('last_error_cause')->nullable();
            $table->string('last_error_actionability')->nullable();
            $table->string('last_error_message_key')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'ca_ws_id_unique');
        });

        $this->addActiveNameUniquenessKey();

        Schema::create('connector_connection_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('connector_account_id');
            $table->string('trigger');
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->string('cause_category')->nullable();
            $table->string('actionability')->nullable();
            $table->string('error_code')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('user_message_key')->nullable();
            $table->json('safe_message_parameters')->nullable();
            $table->string('technical_summary')->nullable();
            $table->string('vendor_request_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['connector_account_id', 'created_at'], 'ccc_account_created_idx');
        });

        Schema::table('connector_connection_checks', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'ccc_ws_account_fk'
            )->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();
        });

        Schema::create('connector_discovery_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('connector_account_id');
            $table->foreignUuid('connector_schema_source_id')
                ->constrained('connector_schema_sources')
                ->restrictOnDelete();
            $table->string('trigger');
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('fields_received')->nullable();
            $table->unsignedInteger('fields_normalized')->nullable();
            $table->unsignedInteger('added_count')->nullable();
            $table->unsignedInteger('changed_count')->nullable();
            $table->unsignedInteger('removed_count')->nullable();
            $table->unsignedInteger('unchanged_count')->nullable();
            $table->string('cause_category')->nullable();
            $table->string('actionability')->nullable();
            $table->string('error_code')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('user_message_key')->nullable();
            $table->string('technical_summary')->nullable();
            $table->string('vendor_request_id')->nullable();
            $table->uuid('snapshot_id')->nullable();
            $table->uuid('previous_snapshot_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['workspace_id', 'id'], 'cdr_ws_id_unique');
            $table->index(['connector_account_id', 'created_at'], 'cdr_account_created_idx');
        });

        Schema::table('connector_discovery_runs', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'cdr_ws_account_fk'
            )->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();
        });

        Schema::create('connector_schema_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('connector_account_id');
            $table->foreignUuid('connector_schema_source_id')
                ->constrained('connector_schema_sources')
                ->restrictOnDelete();
            $table->uuid('discovery_run_id');
            $table->uuid('previous_snapshot_id')->nullable();
            $table->string('schema_version')->nullable();
            $table->unsignedInteger('field_count');
            $table->char('canonical_hash', 64);
            $table->timestamp('captured_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['workspace_id', 'id'], 'css_ws_id_unique');
            $table->index(
                ['connector_account_id', 'connector_schema_source_id', 'created_at'],
                'css_account_source_created_idx'
            );
        });

        Schema::table('connector_schema_snapshots', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'css_ws_account_fk'
            )->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'discovery_run_id'],
                'css_ws_discovery_run_fk'
            )->references(['workspace_id', 'id'])->on('connector_discovery_runs')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'previous_snapshot_id'],
                'css_ws_prevsnap_fk'
            )->references(['workspace_id', 'id'])->on('connector_schema_snapshots')->restrictOnDelete();
        });

        Schema::table('connector_discovery_runs', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'snapshot_id'],
                'cdr_ws_snapshot_fk'
            )->references(['workspace_id', 'id'])->on('connector_schema_snapshots')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'previous_snapshot_id'],
                'cdr_ws_prevsnap_fk'
            )->references(['workspace_id', 'id'])->on('connector_schema_snapshots')->restrictOnDelete();
        });

        Schema::create('connector_schema_snapshot_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('snapshot_id');
            $table->string('external_field_key');
            $table->string('external_label')->nullable();
            $table->string('normalized_data_type');
            $table->boolean('is_required')->nullable();
            $table->boolean('is_multi_value')->nullable();
            $table->boolean('is_localizable')->nullable();
            $table->string('external_scope')->nullable();
            $table->json('normalized_payload');
            $table->char('canonical_hash', 64);
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['snapshot_id', 'external_field_key'], 'cssf_snapshot_field_unique');
            $table->unique(['workspace_id', 'id'], 'cssf_ws_id_unique');
        });

        Schema::table('connector_schema_snapshot_fields', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'snapshot_id'],
                'cssf_ws_snapshot_fk'
            )->references(['workspace_id', 'id'])->on('connector_schema_snapshots')->restrictOnDelete();
        });

        Schema::create('connector_schema_diffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('connector_account_id');
            $table->foreignUuid('connector_schema_source_id')
                ->constrained('connector_schema_sources')
                ->restrictOnDelete();
            $table->uuid('from_snapshot_id')->nullable();
            $table->uuid('to_snapshot_id');
            $table->boolean('is_first_snapshot');
            $table->unsignedInteger('added_count');
            $table->unsignedInteger('changed_count');
            $table->unsignedInteger('removed_count');
            $table->unsignedInteger('unchanged_count');
            $table->timestamp('created_at')->nullable();

            $table->unique('to_snapshot_id', 'csd_to_snapshot_unique');
            $table->index(
                ['connector_account_id', 'connector_schema_source_id', 'created_at'],
                'csd_account_source_created_idx'
            );
            $table->unique(['workspace_id', 'id'], 'csd_ws_id_unique');
        });

        Schema::table('connector_schema_diffs', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'csd_ws_account_fk'
            )->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'from_snapshot_id'],
                'csd_ws_fromsnap_fk'
            )->references(['workspace_id', 'id'])->on('connector_schema_snapshots')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'to_snapshot_id'],
                'csd_ws_tosnap_fk'
            )->references(['workspace_id', 'id'])->on('connector_schema_snapshots')->restrictOnDelete();
        });

        Schema::create('connector_schema_diff_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('connector_schema_diff_id');
            $table->string('change_type');
            $table->string('external_field_key');
            $table->uuid('before_snapshot_field_id')->nullable();
            $table->uuid('after_snapshot_field_id')->nullable();
            $table->json('changed_paths')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(
                ['connector_schema_diff_id', 'external_field_key'],
                'csdi_diff_field_unique'
            );
            $table->index(['connector_schema_diff_id', 'change_type'], 'csdi_diff_change_idx');
        });

        Schema::table('connector_schema_diff_items', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'connector_schema_diff_id'],
                'csdi_ws_diff_fk'
            )->references(['workspace_id', 'id'])->on('connector_schema_diffs')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'before_snapshot_field_id'],
                'csdi_ws_before_field_fk'
            )->references(['workspace_id', 'id'])->on('connector_schema_snapshot_fields')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'after_snapshot_field_id'],
                'csdi_ws_after_field_fk'
            )->references(['workspace_id', 'id'])->on('connector_schema_snapshot_fields')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('connector_schema_diff_items', function (Blueprint $table) {
            $table->dropForeign(['workspace_id', 'after_snapshot_field_id']);
            $table->dropForeign(['workspace_id', 'before_snapshot_field_id']);
            $table->dropForeign(['workspace_id', 'connector_schema_diff_id']);
        });

        Schema::dropIfExists('connector_schema_diff_items');

        Schema::table('connector_schema_diffs', function (Blueprint $table) {
            $table->dropForeign(['workspace_id', 'to_snapshot_id']);
            $table->dropForeign(['workspace_id', 'from_snapshot_id']);
            $table->dropForeign(['workspace_id', 'connector_account_id']);
        });

        Schema::dropIfExists('connector_schema_diffs');

        Schema::table('connector_schema_snapshot_fields', function (Blueprint $table) {
            $table->dropForeign(['workspace_id', 'snapshot_id']);
        });

        Schema::dropIfExists('connector_schema_snapshot_fields');

        Schema::table('connector_discovery_runs', function (Blueprint $table) {
            $table->dropForeign(['workspace_id', 'previous_snapshot_id']);
            $table->dropForeign(['workspace_id', 'snapshot_id']);
        });

        Schema::table('connector_schema_snapshots', function (Blueprint $table) {
            $table->dropForeign(['workspace_id', 'previous_snapshot_id']);
            $table->dropForeign(['workspace_id', 'discovery_run_id']);
            $table->dropForeign(['workspace_id', 'connector_account_id']);
        });

        Schema::dropIfExists('connector_schema_snapshots');

        Schema::table('connector_discovery_runs', function (Blueprint $table) {
            $table->dropForeign(['workspace_id', 'connector_account_id']);
        });

        Schema::dropIfExists('connector_discovery_runs');

        Schema::table('connector_connection_checks', function (Blueprint $table) {
            $table->dropForeign(['workspace_id', 'connector_account_id']);
        });

        Schema::dropIfExists('connector_connection_checks');

        if (Schema::hasColumn('connector_accounts', 'active_name_uniqueness_key')) {
            Schema::table('connector_accounts', function (Blueprint $table) {
                $table->dropUnique('ca_ws_def_name_unique');
                $table->dropColumn('active_name_uniqueness_key');
            });
        }

        Schema::dropIfExists('connector_accounts');
    }

    private function addActiveNameUniquenessKey(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('
                ALTER TABLE connector_accounts
                ADD COLUMN active_name_uniqueness_key VARCHAR(255)
                AS (CASE WHEN deleted_at IS NULL THEN name ELSE NULL END) VIRTUAL
            ');
        } else {
            DB::statement('
                ALTER TABLE connector_accounts
                ADD COLUMN active_name_uniqueness_key TEXT
                GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN name ELSE NULL END) VIRTUAL
            ');
        }

        Schema::table('connector_accounts', function (Blueprint $table) {
            $table->unique(
                ['workspace_id', 'connector_definition_id', 'active_name_uniqueness_key'],
                'ca_ws_def_name_unique'
            );
        });
    }
};
