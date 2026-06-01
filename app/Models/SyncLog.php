<?php

namespace App\Models;

use App\Enums\SyncLogStatus;
use App\Enums\SyncLogType;
use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'type',
        'status',
        'records_processed',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => SyncLogType::class,
            'status' => SyncLogStatus::class,
            'records_processed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
