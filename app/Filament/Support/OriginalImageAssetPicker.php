<?php

namespace App\Filament\Support;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Models\MediaAsset;
use App\Services\Media\MediaAssetSourceResolver;
use App\Support\Workspace\WorkspaceContext;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

final class OriginalImageAssetPicker
{
    public static function make(string $name): Select
    {
        return Select::make($name)
            ->searchable()
            ->getSearchResultsUsing(fn (?string $search): array => self::search($search ?? ''))
            ->getOptionLabelUsing(fn (?string $value): ?string => self::labelForId($value));
    }

    /** @return array<string,string> */
    private static function search(string $search): array
    {
        $query = self::query();

        if (trim($search) !== '') {
            $needle = '%'.trim($search).'%';

            $query->where(function (Builder $nested) use ($needle): void {
                $nested
                    ->where('original_filename', 'like', $needle)
                    ->orWhere('source_url', 'like', $needle);
            });
        }

        return $query
            ->orderBy('original_filename')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (MediaAsset $asset): array => [
                (string) $asset->id => self::label($asset),
            ])
            ->all();
    }

    private static function labelForId(?string $id): ?string
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        $asset = self::query()->whereKey($id)->first();

        return $asset instanceof MediaAsset ? self::label($asset) : null;
    }

    private static function query(): Builder
    {
        return MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', app(WorkspaceContext::class)->id())
            ->whereNull('parent_media_asset_id')
            ->where('asset_type', MediaAssetType::Image->value);
    }

    private static function label(MediaAsset $asset): string
    {
        $name = filled($asset->original_filename)
            ? (string) $asset->original_filename
            : self::urlName($asset) ?? 'Image '.substr((string) $asset->id, 0, 8);

        $source = match (app(MediaAssetSourceResolver::class)->sourceKind($asset)) {
            'managed' => 'Managed',
            'external' => 'External',
            default => 'Немає джерела',
        };

        $attention = in_array($asset->diagnosis_status, [
            MediaDiagnosisStatus::Attention,
            MediaDiagnosisStatus::Failed,
        ], true) ? ' · Потребує уваги' : '';

        return $name.' · '.$source.$attention;
    }

    private static function urlName(MediaAsset $asset): ?string
    {
        if (! filled($asset->source_url)) {
            return null;
        }

        $path = parse_url((string) $asset->source_url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $name = basename($path);

        return $name !== '' ? $name : null;
    }
}
