<?php

namespace App\Enums;

enum ConnectorSchemaVerificationStatus: string
{
    case Verified = 'verified';
    case Stale = 'stale';
    case Broken = 'broken';
    case Unverified = 'unverified';

    public function label(): string
    {
        return match ($this) {
            self::Verified => 'Перевірено',
            self::Stale => 'Застаріле',
            self::Broken => 'Зламане',
            self::Unverified => 'Не перевірено',
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
