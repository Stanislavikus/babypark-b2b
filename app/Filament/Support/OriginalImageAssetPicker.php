<?php

namespace App\Filament\Support;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Models\MediaAsset;
use App\Services\Media\MediaAssetSourceResolver;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;

final class OriginalImageAssetPicker
{
    public static function make(string $name): ModalTableSelect
    {
        return ModalTableSelect::make($name)
            ->tableConfiguration(OriginalImagesTable::class)
            ->getOptionLabelUsing(fn (?string $value): ?string => self::labelForId($value))
            ->selectAction(fn (Action $action): Action => $action
                ->button()
                ->label(__('media_ui.choose_assets'))
                ->modalWidth(Width::FiveExtraLarge)
                ->slideOver(false)
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->modalHeading(__('media_ui.library'))
                ->modalSubmitActionLabel('Підтвердити вибір'));
    }

    /** @return Builder<MediaAsset> */
    public static function scopedOriginalImages(): Builder
    {
        return MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', app(WorkspaceContext::class)->id())
            ->whereNull('parent_media_asset_id')
            ->where('asset_type', MediaAssetType::Image->value);
    }

    private static function labelForId(?string $id): ?string
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        $asset = self::scopedOriginalImages()->whereKey($id)->first();

        return $asset instanceof MediaAsset ? self::displayName($asset) : null;
    }

    public static function displayName(MediaAsset $asset): string
    {
        $name = filled($asset->original_filename)
            ? (string) $asset->original_filename
            : (filled($asset->source_url)
                ? basename(parse_url((string) $asset->source_url, PHP_URL_PATH) ?: (string) $asset->source_url)
                : 'Image '.substr((string) $asset->id, 0, 8));

        $source = match (app(MediaAssetSourceResolver::class)->sourceKind($asset)) {
            'managed' => 'У платформі',
            'external' => 'Зовнішнє',
            default => 'Немає джерела',
        };

        $attention = match ($asset->diagnosis_status) {
            MediaDiagnosisStatus::Pending => ' · Ще не перевірено',
            MediaDiagnosisStatus::Attention, MediaDiagnosisStatus::Failed => ' · Потребує уваги',
            default => '',
        };

        return $name.' · '.$source.$attention;
    }
}
