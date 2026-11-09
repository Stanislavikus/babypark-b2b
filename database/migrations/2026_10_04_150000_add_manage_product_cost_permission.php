<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const CODE = 'manage_product_cost';

    public function up(): void
    {
        if (! DB::table('workspace_permissions')->where('code', self::CODE)->exists()) {
            DB::table('workspace_permissions')->insert([
                'id' => (string) Str::uuid(),
                'code' => self::CODE,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally additive. The canonical permission may have been
        // materialized before this migration by a seeder or deployment step,
        // and merchant role assignments may have been added afterwards.
        // Older application code fails closed because the code is absent from
        // WorkspacePermissions::catalogue(), so preserving the row is safer.
    }
};
