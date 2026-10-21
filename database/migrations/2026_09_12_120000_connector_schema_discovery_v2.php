<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_discovery_runs', function (Blueprint $table) {
            $table->unsignedInteger('fields_identified')->nullable()->after('fields_received');
            $table->unsignedInteger('fields_unclassified')->nullable()->after('fields_normalized');
        });

        Schema::table('connector_schema_snapshots', function (Blueprint $table) {
            $table->string('canonical_hash_version')->default('v1')->after('canonical_hash');
        });

        Schema::table('connector_schema_snapshot_fields', function (Blueprint $table) {
            $table->string('normalized_data_type')->nullable()->change();
            $table->string('normalization_status')->default('normalized')->after('external_label');
            $table->string('normalization_failure_reason')->nullable()->after('normalization_status');
        });

        $this->addNormalizationInvariant();
    }

    public function down(): void
    {
        if ($this->containsV2RuntimeData()) {
            throw new RuntimeException(
                'Cannot downgrade connector schema discovery v2 after v2 runtime data has been published.'
            );
        }

        $this->dropNormalizationInvariant();

        Schema::table('connector_schema_snapshot_fields', function (Blueprint $table) {
            $table->string('normalized_data_type')->nullable(false)->change();
            $table->dropColumn(['normalization_status', 'normalization_failure_reason']);
        });

        Schema::table('connector_schema_snapshots', function (Blueprint $table) {
            $table->dropColumn('canonical_hash_version');
        });

        Schema::table('connector_discovery_runs', function (Blueprint $table) {
            $table->dropColumn(['fields_identified', 'fields_unclassified']);
        });
    }

    private function containsV2RuntimeData(): bool
    {
        return DB::table('connector_schema_snapshots')
            ->where('canonical_hash_version', '!=', 'v1')
            ->exists()
            || DB::table('connector_schema_snapshot_fields')
                ->where(function ($query): void {
                    $query->where('normalization_status', '!=', 'normalized')
                        ->orWhereNull('normalized_data_type')
                        ->orWhereNotNull('normalization_failure_reason');
                })
                ->exists()
            || DB::table('connector_discovery_runs')
                ->where(function ($query): void {
                    $query->whereNotNull('fields_identified')
                        ->orWhereNotNull('fields_unclassified');
                })
                ->exists();
    }

    private function addNormalizationInvariant(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' && $this->mysqlSupportsNamedCheckConstraints()) {
            DB::statement("\n                ALTER TABLE connector_schema_snapshot_fields\n                ADD CONSTRAINT cssf_normalization_state_check CHECK (\n                    (normalization_status = 'normalized'\n                        AND normalized_data_type IS NOT NULL\n                        AND normalization_failure_reason IS NULL)\n                    OR\n                    (normalization_status = 'unclassified'\n                        AND normalized_data_type IS NULL)\n                )\n            ");

            return;
        }

        if ($driver === 'sqlite') {
            $this->createSqliteNormalizationTriggers();

            return;
        }

        if ($driver === 'mysql') {
            $this->createMysqlNormalizationTriggers();
        }
    }

    private function dropNormalizationInvariant(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' && $this->mysqlSupportsNamedCheckConstraints()) {
            DB::statement('ALTER TABLE connector_schema_snapshot_fields DROP CHECK cssf_normalization_state_check');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS cssf_normalization_state_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS cssf_normalization_state_update');
        }

        if ($driver === 'mysql' && ! $this->mysqlSupportsNamedCheckConstraints()) {
            DB::unprepared('DROP TRIGGER IF EXISTS cssf_normalization_state_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS cssf_normalization_state_update');
        }
    }

    private function createSqliteNormalizationTriggers(): void
    {
        foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $event => $suffix) {
            DB::unprepared("\n                CREATE TRIGGER cssf_normalization_state_{$suffix}\n                BEFORE {$event} ON connector_schema_snapshot_fields\n                FOR EACH ROW\n                WHEN NOT (\n                    (NEW.normalization_status = 'normalized'\n                        AND NEW.normalized_data_type IS NOT NULL\n                        AND NEW.normalization_failure_reason IS NULL)\n                    OR\n                    (NEW.normalization_status = 'unclassified'\n                        AND NEW.normalized_data_type IS NULL)\n                )\n                BEGIN\n                    SELECT RAISE(ABORT, 'connector_schema_snapshot_fields normalization state violation');\n                END\n            ");
        }
    }

    private function createMysqlNormalizationTriggers(): void
    {
        foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $event => $suffix) {
            DB::unprepared("\n                CREATE TRIGGER cssf_normalization_state_{$suffix}\n                BEFORE {$event} ON connector_schema_snapshot_fields\n                FOR EACH ROW\n                BEGIN\n                    IF NOT (\n                        (NEW.normalization_status = 'normalized'\n                            AND NEW.normalized_data_type IS NOT NULL\n                            AND NEW.normalization_failure_reason IS NULL)\n                        OR\n                        (NEW.normalization_status = 'unclassified'\n                            AND NEW.normalized_data_type IS NULL)\n                    ) THEN\n                        SIGNAL SQLSTATE '45000'\n                            SET MESSAGE_TEXT = 'connector_schema_snapshot_fields normalization state violation';\n                    END IF;\n                END\n            ");
        }
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
};
