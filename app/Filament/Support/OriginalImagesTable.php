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
            ->contentGrid(['md' => 2, '2xl' => 3])
            ->defaultSort('created_at', 'desc');
    }
}
