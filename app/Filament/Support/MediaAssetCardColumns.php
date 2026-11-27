<?php

namespace App\Filament\Support;

use App\Enums\MediaDiagnosisStatus;
use App\Filament\Resources\MediaAssetResource;
use App\Filament\Resources\ProductResource;
use App\Models\MediaAsset;
use App\Services\Media\MediaAssetLibraryReadService;
use App\Services\Media\MediaAssetSourceResolver;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;

/**
 * Shared visual card used by the Media library and its existing-asset picker.
 * Always keep the card presentation in this one place.
 */
final class MediaAssetCardColumns
{
    public static function make(): array
    {
        return [
            Split::make([
                MediaPreviewFrame::column(
                    ImageColumn::make('asset_preview')
                        ->label('')
                        ->state(fn (MediaAsset $record): ?string => app(MediaAssetSourceResolver::class)->sourceReference($record))
                        ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(ProductResource::placeholderSvg(88)))
                        ->grow(false),
                    MediaPreviewFrame::ASSET_CARD,
                ),
                Stack::make([
                    TextColumn::make('asset_name')
                        ->label('Файл')
                        ->state(fn (MediaAsset $record): string => MediaAssetResource::displayName($record))
                        ->weight(FontWeight::SemiBold)
                        ->searchable(['original_filename', 'source_url'])
                        ->limit(70)
                        ->tooltip(fn (MediaAsset $record): string => MediaAssetResource::displayName($record)),
                    TextColumn::make('asset_meta')
                        ->label('Параметри')
                        ->state(fn (MediaAsset $record): string => MediaAssetResource::dimensions($record).' · '.MediaAssetResource::formatBytes($record->byte_size)),
                    TextColumn::make('asset_usage')
                        ->label('Використання')
                        ->state(fn (MediaAsset $record): string => app(MediaAssetLibraryReadService::class)->usageSummary($record))
                        ->color(fn (MediaAsset $record): string => app(MediaAssetLibraryReadService::class)->isUsed($record) ? 'gray' : 'warning'),
                ])->space(1),
                Stack::make([
                    TextColumn::make('asset_source')
                        ->label('Зберігання')
                        ->state(fn (MediaAsset $record): string => MediaAssetResource::sourceLabel($record))
                        ->badge()
                        ->color(fn (MediaAsset $record): string => MediaAssetResource::sourceColor($record))
                        ->grow(false),
                    TextColumn::make('asset_status')
                        ->label('Технічний стан')
                        ->state(fn (MediaAsset $record): ?string => $record->diagnosis_status === MediaDiagnosisStatus::Ready ? null : MediaAssetResource::diagnosisLabel($record->diagnosis_status))
                        ->badge()
                        ->color(fn (MediaAsset $record): string => MediaAssetResource::diagnosisColor($record))
                        ->grow(false),
                ])->space(1)->grow(false),
            ])->from('md'),
        ];
    }
}
