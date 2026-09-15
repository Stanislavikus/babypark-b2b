<?php

namespace App\Filament\Concerns;

use App\Models\Product;

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

    /** Returns the first image URL from a product's images JSON, or null. */
    public static function firstImage(Product $record): ?string
    {
        $images = $record->images;

        if (is_string($images)) {
            $images = json_decode($images, true);
        }

        return is_array($images) && count($images) > 0 ? $images[0] : null;
    }
}
