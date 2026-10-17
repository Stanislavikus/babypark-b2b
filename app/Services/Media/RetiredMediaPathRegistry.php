<?php

namespace App\Services\Media;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class RetiredMediaPathRegistry
{
    private const ROOT = 'media-retirement/v1';

    public function register(
        string $disk,
        string $path,
        DateTimeInterface $eligibleAt,
    ): string {
        $markerPath = self::ROOT.'/'.hash('sha256', $disk."\0".$path).'.json';
        $payload = json_encode([
            'version' => 1,
            'disk' => $disk,
            'path' => $path,
            'eligible_at' => $eligibleAt->format(DATE_ATOM),
            'registered_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if (! Storage::disk('local')->put($markerPath, $payload)) {
            throw new RuntimeException('Failed to persist retired media cleanup marker.');
        }

        return $markerPath;
    }

    public function forget(string $markerPath): void
    {
        if (! $this->isMarkerPath($markerPath)) {
            return;
        }

        Storage::disk('local')->delete($markerPath);
    }

    /** @return list<string> */
    public function markerPaths(): array
    {
        return array_values(array_filter(
            Storage::disk('local')->files(self::ROOT),
            fn (string $path): bool => $this->isMarkerPath($path),
        ));
    }

    /**
     * @return array{version:int,disk:string,path:string,eligible_at:string,registered_at:string}
     */
    public function read(string $markerPath): array
    {
        if (! $this->isMarkerPath($markerPath)) {
            throw new RuntimeException('Invalid retired media marker path.');
        }

        $raw = Storage::disk('local')->get($markerPath);
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ! is_string($payload['disk'] ?? null)
            || ($payload['disk'] ?? '') === ''
            || ! is_string($payload['path'] ?? null)
            || ($payload['path'] ?? '') === ''
            || ! is_string($payload['eligible_at'] ?? null)
            || ($payload['eligible_at'] ?? '') === ''
            || ! is_string($payload['registered_at'] ?? null)
            || ($payload['registered_at'] ?? '') === ''
        ) {
            throw new RuntimeException('Invalid retired media marker payload.');
        }

        /** @var array{version:int,disk:string,path:string,eligible_at:string,registered_at:string} $payload */
        return $payload;
    }

    private function isMarkerPath(string $path): bool
    {
        return str_starts_with($path, self::ROOT.'/')
            && str_ends_with($path, '.json')
            && ! str_contains($path, '../')
            && ! str_contains($path, "\0");
    }
}
