<?php

use App\Support\Migrations\CoreFieldNamingMigrator;
use Illuminate\Database\Migrations\Migration;

/**
 * DEC-008 / DEC-009 — canonical core field naming correction.
 *
 * Rollback note: forward merge unifies product_name and name into one generic
 * FieldDefinition identity; down() cannot restore the deleted product_name UUID.
 * Product binding UUID and product data are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new CoreFieldNamingMigrator)->up();
    }

    public function down(): void
    {
        (new CoreFieldNamingMigrator)->down();
    }
};
