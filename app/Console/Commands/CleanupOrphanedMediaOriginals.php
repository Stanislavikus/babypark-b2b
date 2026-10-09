<?php

namespace App\Console\Commands;

use App\Jobs\Media\RetiredMediaPathCleanupJob;
use App\Models\MediaAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CleanupOrphanedMediaOriginals extends Command
{
    protected $signature = 'media:cleanup-orphaned-originals
        {--delete : Delete eligible orphaned managed Originals. Without this flag the command is dry-run only.}';

    protected $description = 'Find managed Original files with no MediaAsset row, respecting the 14-day recovery grace';

    public function handle(): int
    {
        $disk = 'public';
        $root = 'media/originals';
        $delete = (bool) $this->option('delete');
        $cutoff = now()->subDays(RetiredMediaPathCleanupJob::REPLACE_RETENTION_DAYS)->getTimestamp();
        $eligible = 0;
        $deleted = 0;
        $skippedFresh = 0;
        $skippedReferenced = 0;

        foreach (Storage::disk($disk)->allFiles($root) as $path) {
            $normalized = ltrim((string) $path, '/');

            if (! str_starts_with($normalized, $root.'/') || str_contains($normalized, '../')) {
                continue;
            }

            if (MediaAsset::withoutWorkspaceScope()
                ->where('storage_disk', $disk)
                ->where('storage_path', $normalized)
                ->exists()
            ) {
                $skippedReferenced++;

                continue;
            }

            try {
                $lastModified = Storage::disk($disk)->lastModified($normalized);
            } catch (Throwable) {
                $this->warn('Не вдалося визначити вік файлу: '.$normalized);

                continue;
            }

            if ($lastModified > $cutoff) {
                $skippedFresh++;

                continue;
            }

            $eligible++;
            $this->line(($delete ? 'DELETE ' : 'DRY-RUN ').$normalized);

            if ($delete && Storage::disk($disk)->delete($normalized)) {
                $deleted++;
            }
        }

        $this->info(sprintf(
            '%s: eligible=%d, deleted=%d, protected_by_grace=%d, referenced=%d.',
            $delete ? 'Cleanup complete' : 'Dry-run complete',
            $eligible,
            $deleted,
            $skippedFresh,
            $skippedReferenced,
        ));

        return self::SUCCESS;
    }
}