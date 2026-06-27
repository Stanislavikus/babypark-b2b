<?php

namespace Database\Factories;

use App\Models\Contractor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Contractor>
 */
class ContractorFactory extends Factory
{
    protected $model = Contractor::class;

    public function definition(): array
    {
        return [
            'onec_guid' => (string) Str::uuid(),
            'name' => fake()->company(),
            'short_name' => fake()->companySuffix(),
            'login' => fake()->unique()->userName(),
            'password' => Hash::make('password'),
            'is_active' => true,
            'payment_delay_days' => 14,
            'credit_limit' => 100000,
            'current_debt' => 0,
            'synced_at' => now(),
        ];
    }
}
