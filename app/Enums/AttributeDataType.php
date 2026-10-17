<?php

namespace App\Enums;

enum AttributeDataType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Number = 'number';
    case Decimal = 'decimal';
    case Money = 'money';
    case Boolean = 'boolean';
    case Date = 'date';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Image = 'image';
    case Url = 'url';
    case Computed = 'computed';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Текст',
            self::LongText => 'Довгий текст',
            self::Number => 'Число',
            self::Decimal => 'Десяткове',
            self::Money => 'Гроші',
            self::Boolean => 'Так/Ні',
            self::Date => 'Дата',
            self::Select => 'Вибір',
            self::MultiSelect => 'Мультивибір',
            self::Image => 'Зображення',
            self::Url => 'Посилання',
            self::Computed => 'Обчислюване',
        };
    }
}
