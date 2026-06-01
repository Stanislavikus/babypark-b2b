<?php

namespace App\Enums;

enum OrderStatus: string
{
    case New = 'new';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новий',
            self::Pending => 'Очікує',
            self::Confirmed => 'Підтверджено',
            self::InProgress => 'В обробці',
            self::Shipped => 'Відправлено',
            self::Delivered => 'Доставлено',
            self::Cancelled => 'Скасовано',
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
