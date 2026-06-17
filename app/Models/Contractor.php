<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'account_manager_id',
        'backup_manager_id',
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

    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    public function backupManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'backup_manager_id');
    }

    /**
     * Returns ['name' => ..., 'phone' => ...] for the effective manager contact.
     *
     * Priority:
     * 1. No account_manager_id set → use manager_name / manager_phone (legacy fields)
     * 2. account_manager_id set + not on vacation → use that user's name/phone
     * 3. account_manager on vacation + backup_manager available → use backup's name/phone
     * 4. Fallback → manager_name / manager_phone
     */
    public function effectiveManager(): array
    {
        $fallback = [
            'name' => $this->manager_name,
            'phone' => $this->manager_phone,
        ];

        if (! $this->account_manager_id) {
            return $fallback;
        }

        $manager = $this->accountManager;

        if (! $manager) {
            return $fallback;
        }

        if (! $manager->isOnVacation()) {
            return [
                'name' => $manager->name,
                'phone' => $manager->phone,
            ];
        }

        // Account manager is on vacation — try backup
        if ($this->backup_manager_id) {
            $backup = $this->backupManager;

            if ($backup && ! $backup->isOnVacation()) {
                return [
                    'name' => $backup->name,
                    'phone' => $backup->phone,
                ];
            }
        }

        return $fallback;
    }
}
