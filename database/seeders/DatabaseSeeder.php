<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            WorkspaceSeeder::class,
            AttributeDefinitionSeeder::class,
            B2BSeeder::class,
            DeliverySettingSeeder::class,
        ]);
    }
}
