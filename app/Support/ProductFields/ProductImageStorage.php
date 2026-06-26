<?php

namespace App\Support\ProductFields;

use Illuminate\Support\Facades\Storage;

class ProductImageStorage
{
    /** Disk used for product image uploads (public in dev, s3 in production per DEPLOY.md). */
    public static function disk(): string
    {
        $default = config('filesystems.default', 'public');

        return match ($default) {
            's3' => 's3',
            default => 'public',
        };
    }

    public static function urlFromPath(string $path): string
    {
        return Storage::disk(self::disk())->url($path);
    }

    /** Reverse a stored image URL to a disk-relative path, or null for external URLs. */
    public static function pathFromUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        foreach (array_unique([self::disk(), 'public', 's3']) as $disk) {
            try {
                $baseUrl = Storage::disk($disk)->url('');
            } catch (\Throwable) {
                continue;
            }

            if (str_starts_with($url, $baseUrl)) {
                return ltrim(substr($url, strlen($baseUrl)), '/');
            }
        }

        return null;
    }
}
