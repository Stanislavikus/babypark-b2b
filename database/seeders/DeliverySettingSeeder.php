<?php

namespace Database\Seeders;

use App\Models\DeliverySetting;
use Illuminate\Database\Seeder;

class DeliverySettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['city' => 'Київ',        'free_from' => 2000, 'delivery_price' => 150,  'sort_order' => 1],
            ['city' => 'Харків',      'free_from' => 3000, 'delivery_price' => 200,  'sort_order' => 2],
            ['city' => 'Одеса',       'free_from' => 3000, 'delivery_price' => 200,  'sort_order' => 3],
            ['city' => 'Дніпро',      'free_from' => 3000, 'delivery_price' => 200,  'sort_order' => 4],
            ['city' => 'Інші міста',  'free_from' => 5000, 'delivery_price' => 250,  'sort_order' => 5],
        ];

        foreach ($settings as $data) {
            DeliverySetting::firstOrCreate(
                ['city' => $data['city']],
                [
                    'free_from' => $data['free_from'],
                    'delivery_price' => $data['delivery_price'],
                    'is_active' => true,
                    'sort_order' => $data['sort_order'],
                ]
            );
        }
    }
}
