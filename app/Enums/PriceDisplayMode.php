<?php

namespace App\Enums;

enum PriceDisplayMode: string
{
    case TaxInclusivePrimary = 'tax_inclusive_primary';
    case TaxExclusivePrimary = 'tax_exclusive_primary';
    case BothEqual = 'both_equal';

    public function label(): string
    {
        return match ($this) {
            self::TaxInclusivePrimary => 'Ціна з податком — основна',
            self::TaxExclusivePrimary => 'Ціна без податку — основна',
            self::BothEqual => 'Показувати обидві однаково',
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
