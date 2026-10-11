<?php

namespace App\Filament\Support;

use Filament\Tables\Table;

final class OriginalImagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn () => OriginalImageAssetPicker::scopedOriginalImages())
            ->columns(MediaAssetCardColumns::make())
            ->contentGrid(['default' => 1, 'lg' => 2])
            ->defaultSort('created_at', 'desc');
    }
}
