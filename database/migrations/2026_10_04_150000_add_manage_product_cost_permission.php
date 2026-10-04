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
        $permissionIds = DB::table('workspace_permissions')
            ->where('code', self::CODE)
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('workspace_role_permissions')
            ->whereIn('workspace_permission_id', $permissionIds)
            ->delete();

        DB::table('workspace_permissions')
            ->whereIn('id', $permissionIds)
            ->delete();
    }
};
