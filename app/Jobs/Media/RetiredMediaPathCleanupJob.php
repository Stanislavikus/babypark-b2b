<?php

namespace App\Jobs\Media;

use App\Models\MediaAsset;
use App\Services\Media\RetiredMediaPathRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class RetiredMediaPathCleanupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const REPLACE_RETENTION_DAYS = 14;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly ?string $eligibleAt = null,
        public readonly ?string $markerPath = null,
    ) {}

    public function handle(RetiredMediaPathRegistry $registry): void
    {
        if (! $this->isOwnedOriginalPath()) {
            $this->forgetMarker($registry);

            return;
        }

        if ($this->eligibleAt !== null && now()->lt(CarbonImmutable::parse($this->eligibleAt))) {
            return;
        }

        $stillReferenced = MediaAsset::withoutWorkspaceScope()
            ->where('storage_disk', $this->disk)
            ->where('storage_path', $this->path)
            ->exists();

        if ($stillReferenced) {
            $this->forgetMarker($registry);

            return;
        }

        $storage = Storage::disk($this->disk);

        if ($storage->exists($this->path) && ! $storage->delete($this->path)) {
            throw new RuntimeException('Failed to clean retired managed media path.');
        }

        $this->forgetMarker($registry);
    }

    private function forgetMarker(RetiredMediaPathRegistry $registry): void
    {
        if ($this->markerPath !== null) {
            $registry->forget($this->markerPath);
        }
    }

    private function isOwnedOriginalPath(): bool
    {
        $path = ltrim($this->path, '/');

        return str_starts_with($path, 'media/originals/')
            && ! str_contains($path, '../')
            && ! str_contains($path, "\0");
    }
}
