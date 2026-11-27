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

        Schema::create('external_record_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('connector_account_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('external_identifier', 255);
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'connector_account_id', 'product_id', 'external_identifier'],
                'erl_ws_account_product_external_unique',
            );
            $table->unique(
                ['workspace_id', 'connector_account_id', 'product_variant_id', 'external_identifier'],
                'erl_ws_account_variant_external_unique',
            );
        });

        Schema::table('external_record_links', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'connector_account_id'],
                'erl_ws_account_fk',
            )->references(['workspace_id', 'id'])->on('connector_accounts')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'product_id'],
                'erl_ws_product_fk',
            )->references(['workspace_id', 'id'])->on('products')->restrictOnDelete();

            $table->foreign(
                ['workspace_id', 'product_variant_id'],
                'erl_ws_variant_fk',
            )->references(['workspace_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        if ($driver === 'mysql' && $this->mysqlSupportsNamedCheckConstraints()) {
            DB::statement('
                ALTER TABLE external_record_links
                ADD CONSTRAINT erl_subject_xor_check CHECK (
                    (product_id IS NULL AND product_variant_id IS NOT NULL)
                    OR (product_id IS NOT NULL AND product_variant_id IS NULL)
                )
            ');
        }

        if ($driver === 'sqlite') {
            $this->createSqliteSubjectXorTriggers();
        }

        if ($driver === 'mysql' && ! $this->mysqlSupportsNamedCheckConstraints()) {
            $this->createMysqlSubjectXorTriggers();
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS erl_subject_xor_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS erl_subject_xor_update');

            Schema::table('external_record_links', function (Blueprint $table) {
                $table->dropForeign(['workspace_id', 'product_variant_id']);
                $table->dropForeign(['workspace_id', 'product_id']);
                $table->dropForeign(['workspace_id', 'connector_account_id']);
            });
        }

        if ($driver === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS erl_subject_xor_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS erl_subject_xor_update');

            Schema::table('external_record_links', function (Blueprint $table) {
                $table->dropForeign('erl_ws_variant_fk');
                $table->dropForeign('erl_ws_product_fk');
                $table->dropForeign('erl_ws_account_fk');
            });
        }

        Schema::dropIfExists('external_record_links');
    }

    private function mysqlSupportsNamedCheckConstraints(): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return false;
        }

        $version = (string) DB::selectOne('SELECT VERSION() as version')->version;

        if (! preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $matches)) {
            return false;
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];
        $patch = (int) $matches[3];

        if ($major > 8) {
            return true;
        }

        if ($major < 8) {
            return false;
        }

        if ($minor > 0) {
            return true;
        }

        return $patch >= 16;
    }

    private function createSqliteSubjectXorTriggers(): void
    {
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

    private function createMysqlSubjectXorTriggers(): void
    {
        DB::unprepared('
            CREATE TRIGGER erl_subject_xor_insert
            BEFORE INSERT ON external_record_links
            FOR EACH ROW
            BEGIN
                IF NOT (
                    (NEW.product_id IS NULL AND NEW.product_variant_id IS NOT NULL)
                    OR (NEW.product_id IS NOT NULL AND NEW.product_variant_id IS NULL)
                ) THEN
                    SIGNAL SQLSTATE \'45000\'
                        SET MESSAGE_TEXT = \'external_record_links subject xor violation\';
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER erl_subject_xor_update
            BEFORE UPDATE ON external_record_links
            FOR EACH ROW
            BEGIN
                IF NOT (
                    (NEW.product_id IS NULL AND NEW.product_variant_id IS NOT NULL)
                    OR (NEW.product_id IS NOT NULL AND NEW.product_variant_id IS NULL)
                ) THEN
                    SIGNAL SQLSTATE \'45000\'
                        SET MESSAGE_TEXT = \'external_record_links subject xor violation\';
                END IF;
            END
        ');
    }
};
