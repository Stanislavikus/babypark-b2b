<?php

namespace Database\Seeders;

use App\Models\WorkspacePermission;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Database\Seeder;

class WorkspaceRbacPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (WorkspacePermissions::catalogue() as $code) {
            WorkspacePermission::query()->firstOrCreate(['code' => $code]);
        }
    }
}
