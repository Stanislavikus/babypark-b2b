<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use App\Services\Media\RetiredMediaPathRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CleanupOrphanedMediaOriginals extends Command
{
    protected $signature = 'media:cleanup-orphaned-originals
        {--delete : Delete eligible registered retired paths. Without this flag the command is dry-run only.}';

    protected $description = 'Recover registered retired managed Original paths whose queued cleanup did not complete';

    public function handle(RetiredMediaPathRegistry $registry): int
    {
        $delete = (bool) $this->option('delete');
        $eligible = 0;
        $cleaned = 0;
        $protectedByGrace = 0;
        $referenced = 0;
        $invalid = 0;
        $failed = 0;

        foreach ($registry->markerPaths() as $markerPath) {
            try {
                $record = $registry->read($markerPath);
                $eligibleAt = CarbonImmutable::parse($record['eligible_at']);
            } catch (Throwable $e) {
                $invalid++;
                $this->warn('Некоректний cleanup marker: '.$markerPath.' · '.$e->getMessage());

                continue;
            }

            $disk = $record['disk'];
            $path = ltrim($record['path'], '/');

            if (! $this->isOwnedOriginalPath($path)) {
                $invalid++;
                $this->warn('Cleanup marker поза дозволеним media/originals namespace: '.$markerPath);

                continue;
            }

            if (MediaAsset::withoutWorkspaceScope()
                ->where('storage_disk', $disk)
                ->where('storage_path', $path)
                ->exists()
            ) {
                $referenced++;

                continue;
            }

            if (now()->lt($eligibleAt)) {
                $protectedByGrace++;

                continue;
            }

            $eligible++;
            $this->line(($delete ? 'DELETE ' : 'DRY-RUN ').$disk.':'.$path);

            if (! $delete) {
                continue;
            }

            try {
                $storage = Storage::disk($disk);

                if ($storage->exists($path) && ! $storage->delete($path)) {
                    $failed++;
                    $this->warn('Не вдалося видалити retired path: '.$disk.':'.$path);

                    continue;
                }

                $registry->forget($markerPath);
                $cleaned++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn('Cleanup failed: '.$disk.':'.$path.' · '.$e->getMessage());
            }
        }

        $this->info(sprintf(
            '%s: eligible=%d, cleaned=%d, protected_by_grace=%d, referenced=%d, invalid=%d, failed=%d.',
            $delete ? 'Cleanup complete' : 'Dry-run complete',
            $eligible,
            $cleaned,
            $protectedByGrace,
            $referenced,
            $invalid,
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function isOwnedOriginalPath(string $path): bool
    {
        return str_starts_with($path, 'media/originals/')
            && ! str_contains($path, '../')
            && ! str_contains($path, "\0");
    }
}
