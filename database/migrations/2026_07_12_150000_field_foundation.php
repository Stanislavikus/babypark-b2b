<?php

use App\Support\Migrations\FieldFoundationMigrator;
use Illuminate\Database\Migrations\Migration;

/**
 * GAP-016 — Field Foundation migration.
 *
 * Recovery after partial failure: inspect which new tables/columns exist.
 * If field_definitions (or siblings) exist but old tables are gone, migration
 * already completed — do not re-run. If new tables exist while old tables also
 * exist, manually drop new tables/columns in FK-safe order before retrying.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new FieldFoundationMigrator)->up();
    }

    public function down(): void
    {
        (new FieldFoundationMigrator)->down();
    }
};
