<?php

namespace App\Filament\Support;

use App\Models\MediaAsset;
use App\Services\Media\MediaAssetSourceResolver;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class OriginalImagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn () => OriginalImageAssetPicker::scopedOriginalImages())
            ->columns([
                MediaPreviewFrame::column(
                    ImageColumn::make('asset_preview')
                        ->label('Зображення')
                        ->state(fn (MediaAsset $record): ?string => app(MediaAssetSourceResolver::class)->sourceReference($record)),
                    MediaPreviewFrame::BRAND_LIST,
                ),
                TextColumn::make('original_filename')
                    ->label('Файл')
                    ->state(fn (MediaAsset $record): string => OriginalImageAssetPicker::displayName($record))
                    ->searchable(['original_filename', 'source_url'])
                    ->wrap(),
                TextColumn::make('dimensions')
                    ->label('Розмір')
                    ->state(fn (MediaAsset $record): string => $record->width_px !== null && $record->height_px !== null
                        ? $record->width_px.' × '.$record->height_px.' px'
                        : '—'),
            ])
            ->defaultSort('original_filename');
    }
}
