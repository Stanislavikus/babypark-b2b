<?php

namespace Database\Seeders;

use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class WorkspacePermissionSeeder extends Seeder
{
    public function run(): void
    {
        Permission::findOrCreate(WorkspacePermissions::MANAGE_TAX_SETTINGS, 'web');
    }
}
