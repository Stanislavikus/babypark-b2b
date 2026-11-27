<?php

namespace Database\Seeders;

use App\Models\Workspace;
use Illuminate\Database\Seeder;

class WorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $existingDefault = Workspace::query()->where('is_default', true)->first();

        if ($existingDefault !== null) {
            Workspace::query()
                ->where('id', '!=', $existingDefault->id)
                ->update(['is_default' => false]);

            $existingDefault->update(['name' => 'Babypark', 'is_default' => true]);

            return;
        }

        Workspace::query()->create([
            'name' => 'Babypark',
            'is_default' => true,
        ]);
    }
}
