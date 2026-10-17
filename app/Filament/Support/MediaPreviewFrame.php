<?php

namespace App\Filament\Support;

use Filament\Infolists\Components\ImageEntry;
use Filament\Tables\Columns\ImageColumn;

final class MediaPreviewFrame
{
    public const ASSET_CARD = 'asset-card';

    public const DETAIL = 'detail';

    public const BRAND_FORM = 'brand-form';

    public const BRAND_LIST = 'brand-list';

    public static function entry(ImageEntry $entry, string $variant): ImageEntry
    {
        return $entry
            ->alignStart()
            ->extraAttributes([
                'class' => self::classes($variant),
            ])
            ->extraImgAttributes([
                'class' => 'bp-media-preview-frame__image',
            ]);
    }

    public static function column(ImageColumn $column, string $variant): ImageColumn
    {
        return $column
            ->alignStart()
            ->extraAttributes([
                'class' => self::classes($variant),
            ])
            ->extraImgAttributes([
                'class' => 'bp-media-preview-frame__image',
            ]);
    }

    private static function classes(string $variant): string
    {
        return 'bp-media-preview-frame bp-media-preview-frame--'.$variant;
    }
}
