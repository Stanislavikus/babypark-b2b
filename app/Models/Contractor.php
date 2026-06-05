<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Contractor extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'onec_guid',
        'name',
        'short_name',
        'edrpou',
        'ipn',
        'manager_name',
        'manager_phone',
        'email',
        'login',
        'password',
        'is_active',
        'payment_delay_days',
        'credit_limit',
        'current_debt',
        'synced_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'payment_delay_days' => 'integer',
            'credit_limit' => 'decimal:2',
            'current_debt' => 'decimal:2',
            'synced_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
