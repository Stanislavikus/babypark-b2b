<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id'], 'wu_ws_user_unique');
            $table->unique(['id', 'workspace_id'], 'wu_ws_id_unique');
        });

        Schema::create('workspace_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('name');
            $table->string('template_key')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name'], 'wr_ws_name_unique');
            $table->unique(['workspace_id', 'template_key'], 'wr_ws_template_key_unique');
            $table->unique(['id', 'workspace_id'], 'wr_ws_id_unique');
        });

        Schema::create('workspace_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
        });

        Schema::create('workspace_user_roles', function (Blueprint $table) {
            $table->foreignUuid('workspace_id');
            $table->uuid('workspace_user_id');
            $table->uuid('workspace_role_id');

            $table->unique(['workspace_user_id', 'workspace_role_id'], 'wur_user_role_unique');
            $table->index(['workspace_user_id', 'workspace_id'], 'wur_ws_user_id_idx');
            $table->index(['workspace_role_id', 'workspace_id'], 'wur_ws_role_id_idx');
        });

        Schema::table('workspace_user_roles', function (Blueprint $table) {
            $table->foreign(
                ['workspace_user_id', 'workspace_id'],
                'wur_ws_user_fk',
            )->references(['id', 'workspace_id'])->on('workspace_users')->restrictOnDelete();

            $table->foreign(
                ['workspace_role_id', 'workspace_id'],
                'wur_ws_role_fk',
            )->references(['id', 'workspace_id'])->on('workspace_roles')->restrictOnDelete();
        });

        Schema::create('workspace_role_permissions', function (Blueprint $table) {
            $table->foreignUuid('workspace_id');
            $table->uuid('workspace_role_id');
            $table->uuid('workspace_permission_id');

            $table->unique(['workspace_role_id', 'workspace_permission_id'], 'wrp_role_permission_unique');
            $table->index(['workspace_role_id', 'workspace_id'], 'wrp_ws_role_id_idx');
        });

        Schema::table('workspace_role_permissions', function (Blueprint $table) {
            $table->foreign(
                ['workspace_role_id', 'workspace_id'],
                'wrp_ws_role_fk',
            )->references(['id', 'workspace_id'])->on('workspace_roles')->restrictOnDelete();

            $table->foreign('workspace_permission_id', 'wrp_permission_fk')
                ->references('id')
                ->on('workspace_permissions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('workspace_role_permissions', function (Blueprint $table) {
                $table->dropForeign('wrp_ws_role_fk');
                $table->dropForeign('wrp_permission_fk');
            });

            Schema::table('workspace_user_roles', function (Blueprint $table) {
                $table->dropForeign('wur_ws_user_fk');
                $table->dropForeign('wur_ws_role_fk');
            });
        } else {
            Schema::table('workspace_role_permissions', function (Blueprint $table) {
                $table->dropForeign(['workspace_role_id', 'workspace_id']);
                $table->dropForeign(['workspace_permission_id']);
            });

            Schema::table('workspace_user_roles', function (Blueprint $table) {
                $table->dropForeign(['workspace_user_id', 'workspace_id']);
                $table->dropForeign(['workspace_role_id', 'workspace_id']);
            });
        }

        Schema::dropIfExists('workspace_role_permissions');
        Schema::dropIfExists('workspace_user_roles');
        Schema::dropIfExists('workspace_permissions');
        Schema::dropIfExists('workspace_roles');
        Schema::dropIfExists('workspace_users');
    }
};
