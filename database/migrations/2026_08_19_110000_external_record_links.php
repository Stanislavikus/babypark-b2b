<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_record_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('connector_account_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('external_identifier', 255);
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'erl_ws_id_unique');
            $table->unique(
                ['workspace_id', 'connector_account_id', 'product_id', 'external_identifier'],
                'erl_ws_account_product_ext_unique',
            );
            $table->unique(
                ['workspace_id', 'connector_account_id', 'product_variant_id', 'external_identifier'],
                'erl_ws_account_variant_ext_unique',
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

        $this->addSubjectXorInvariant();
    }

    public function down(): void
    {
        $this->dropSubjectXorInvariant();

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('external_record_links', function (Blueprint $table) {
                $table->dropForeign('erl_ws_variant_fk');
                $table->dropForeign('erl_ws_product_fk');
                $table->dropForeign('erl_ws_account_fk');
            });
        } else {
            Schema::table('external_record_links', function (Blueprint $table) {
                $table->dropForeign(['workspace_id', 'product_variant_id']);
                $table->dropForeign(['workspace_id', 'product_id']);
                $table->dropForeign(['workspace_id', 'connector_account_id']);
            });
        }

        Schema::dropIfExists('external_record_links');
    }

    private function addSubjectXorInvariant(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('
                ALTER TABLE external_record_links
                ADD CONSTRAINT erl_subject_xor_chk CHECK (
                    (product_id IS NULL AND product_variant_id IS NOT NULL)
                    OR
                    (product_id IS NOT NULL AND product_variant_id IS NULL)
                )
            ');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('
                CREATE TRIGGER erl_subject_xor_insert
                BEFORE INSERT ON external_record_links
                BEGIN
                    SELECT RAISE(ABORT, \'external_record_links subject XOR violation\')
                    WHERE NOT (
                        (NEW.product_id IS NULL AND NEW.product_variant_id IS NOT NULL)
                        OR
                        (NEW.product_id IS NOT NULL AND NEW.product_variant_id IS NULL)
                    );
                END
            ');

            DB::statement('
                CREATE TRIGGER erl_subject_xor_update
                BEFORE UPDATE ON external_record_links
                BEGIN
                    SELECT RAISE(ABORT, \'external_record_links subject XOR violation\')
                    WHERE NOT (
                        (NEW.product_id IS NULL AND NEW.product_variant_id IS NOT NULL)
                        OR
                        (NEW.product_id IS NOT NULL AND NEW.product_variant_id IS NULL)
                    );
                END
            ');
        }
    }

    private function dropSubjectXorInvariant(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE external_record_links DROP CHECK erl_subject_xor_chk');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS erl_subject_xor_insert');
            DB::statement('DROP TRIGGER IF EXISTS erl_subject_xor_update');
        }
    }
};
