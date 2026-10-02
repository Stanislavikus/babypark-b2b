<?php

namespace App\Enums;

enum ConnectorSchemaDiffItemChangeType: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Changed = 'changed';

    public function label(): string
    {
        return 'connectors.enums.schema_diff_item_change_type.'.$this->value;
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
