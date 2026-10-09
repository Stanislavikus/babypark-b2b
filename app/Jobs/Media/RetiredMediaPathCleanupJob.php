<?php

namespace App\Jobs\Media;

use App\Models\MediaAsset;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

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
    ) {}

    public function handle(): void
    {
        if (! $this->isOwnedOriginalPath()) {
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
            return;
        }

        Storage::disk($this->disk)->delete($this->path);
    }

    private function isOwnedOriginalPath(): bool
    {
        $path = ltrim($this->path, '/');

        return str_starts_with($path, 'media/originals/')
            && ! str_contains($path, '../')
            && ! str_contains($path, "\0");
    }
}