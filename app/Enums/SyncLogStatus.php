<?php

namespace App\Enums;

enum SyncLogStatus: string
{
    case Success = 'success';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Успішно',
            self::Error => 'Помилка',
        };
    }
}
