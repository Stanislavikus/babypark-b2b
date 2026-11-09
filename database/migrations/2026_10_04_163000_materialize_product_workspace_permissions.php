<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private const CODES = [
        'manage_product_structure',
        'manage_products',
    ];

    public function up(): void
    {
        foreach (self::CODES as $code) {
            if (DB::table('workspace_permissions')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('workspace_permissions')->insert([
                'id' => (string) Str::uuid(),
                'code' => $code,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally additive. These canonical permissions may pre-date this
        // migration through a seeder and may already be assigned to merchant roles.
        // Their provenance cannot be distinguished safely during rollback.
    }
};
