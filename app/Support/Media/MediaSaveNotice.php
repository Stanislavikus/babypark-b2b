<?php

namespace App\Support\Media;

final class MediaSaveNotice
{
    public static function unchanged(bool $otherChangesSaved): string
    {
        return $otherChangesSaved
            ? 'Зображення вже актуальне. Інші зміни збережено.'
            : 'Зображення вже актуальне. Інших змін немає.';
    }

    public static function reused(): string
    {
        return 'Зображення вже є в Assets. Використано існуючий файл без дублювання.';
    }
}
