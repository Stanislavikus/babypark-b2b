<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Active = 'active';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Активна',
            self::Confirmed => 'Підтверджено',
            self::Cancelled => 'Скасовано',
            self::Expired => 'Протерміновано',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
