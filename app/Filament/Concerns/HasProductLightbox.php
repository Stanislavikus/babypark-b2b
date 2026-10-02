<?php

namespace App\Filament\Concerns;

use App\Models\Product;
use App\Services\Catalog\ProductMediaReadService;

trait HasProductLightbox
{
    /**
     * Returns extra <img> attributes for thumbnail that opens the shared JS lightbox
     * (bpOpenLightbox injected by panel provider BODY_END renderHook).
     *
     * @return array<string, string>
     */
    public static function lightboxImgAttributes(Product $record): array
    {
        $url = self::firstImage($record);

        if (! $url) {
            return [
                'class' => 'rounded object-cover',
                'style' => 'cursor: default;',
            ];
        }

        $safe = e($url);
        $title = e($record->name);

        return [
            'class' => 'rounded object-cover',
            'style' => 'cursor: zoom-in;',
            'title' => 'Натисніть для збільшення',
            'onclick' => "event.stopPropagation();event.preventDefault();bpOpenLightbox('{$safe}','{$title}')",
        ];
    }

    /** Returns the first Master Product media URL, with legacy JSON fallback. */
    public static function firstImage(Product $record): ?string
    {
        return app(ProductMediaReadService::class)->firstImageUrl($record);
    }
}
