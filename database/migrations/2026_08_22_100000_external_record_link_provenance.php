<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('external_record_links', function (Blueprint $table) {
            $table->string('trust_origin')->nullable();
            $table->string('external_record_discriminator')->nullable();
            $table->uuid('established_by_workspace_user_id')->nullable();
            $table->timestamp('established_at')->nullable();
        });

        Schema::table('external_record_links', function (Blueprint $table) {
            $table->foreign(
                ['established_by_workspace_user_id', 'workspace_id'],
                'erl_established_actor_ws_fk',
            )->references(['id', 'workspace_id'])->on('workspace_users')->restrictOnDelete();
        });

        if ($driver === 'sqlite') {
            $this->recreateSqliteSubjectXorTriggers();
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('external_record_links', function (Blueprint $table) {
                $table->dropForeign(['established_by_workspace_user_id', 'workspace_id']);
            });
        }

        if ($driver === 'mysql') {
            Schema::table('external_record_links', function (Blueprint $table) {
                $table->dropForeign('erl_established_actor_ws_fk');
            });
        }

        Schema::table('external_record_links', function (Blueprint $table) {
            $table->dropColumn([
                'trust_origin',
                'external_record_discriminator',
                'established_by_workspace_user_id',
                'established_at',
            ]);
        });

        if ($driver === 'sqlite') {
            $this->recreateSqliteSubjectXorTriggers();
        }
    }

    private function recreateSqliteSubjectXorTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS erl_subject_xor_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS erl_subject_xor_update');

        DB::unprepared('
            CREATE TRIGGER erl_subject_xor_insert
            BEFORE INSERT ON external_record_links
            FOR EACH ROW
            WHEN NOT (
                (NEW.product_id IS NULL AND NEW.product_variant_id IS NOT NULL)
                OR (NEW.product_id IS NOT NULL AND NEW.product_variant_id IS NULL)
            )
            BEGIN
                SELECT RAISE(ABORT, \'external_record_links subject xor violation\');
            END;
        ');

        DB::unprepared('
            CREATE TRIGGER erl_subject_xor_update
            BEFORE UPDATE ON external_record_links
            FOR EACH ROW
            WHEN NOT (
                (NEW.product_id IS NULL AND NEW.product_variant_id IS NOT NULL)
                OR (NEW.product_id IS NOT NULL AND NEW.product_variant_id IS NULL)
            )
            BEGIN
                SELECT RAISE(ABORT, \'external_record_links subject xor violation\');
            END;
        ');
    }
};
