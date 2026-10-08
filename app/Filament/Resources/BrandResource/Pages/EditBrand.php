<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\BrandManager;
use App\Services\Media\OriginalImageIngestService;
use App\Support\Media\Exceptions\MediaIngestException;
use App\Support\Workspace\WorkspaceContext;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $workspace = app(WorkspaceContext::class)->current();
        $upload = $this->logoUpload($data['logo_upload'] ?? null);
        $imageIngest = app(OriginalImageIngestService::class);
        $prepared = null;

        if ($upload instanceof UploadedFile) {
            try {
                $prepared = $imageIngest->prepare($upload);
            } catch (MediaIngestException $exception) {
                throw ValidationException::withMessages([
                    'data.logo_upload' => $exception->getMessage(),
                ]);
            }
        }

        $storedPaths = [];

        try {
            return DB::transaction(function () use (
                $actor,
                $workspace,
                $record,
                $data,
                $prepared,
                $imageIngest,
                &$storedPaths,
            ): Model {
                if ($prepared !== null) {
                    $lockedWorkspace = Workspace::query()
                        ->whereKey($workspace->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $ingested = $imageIngest->ingestPrepared($actor, $lockedWorkspace, $prepared);
                    $data['logo_media_asset_id'] = (string) $ingested->asset->id;

                    if ($ingested->newStoredPath !== null) {
                        $storedPaths[] = $ingested->newStoredPath;
                    }
                }

                unset($data['logo_upload']);

                return app(BrandManager::class)->update(
                    $actor,
                    $workspace,
                    $record,
                    $data,
                );
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $storedPath) {
                Storage::disk($storedPath['disk'])->delete($storedPath['path']);
            }

            if ($exception instanceof MediaIngestException) {
                throw ValidationException::withMessages([
                    'data.logo_upload' => $exception->getMessage(),
                ]);
            }

            throw $exception;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    private function logoUpload(mixed $state): ?UploadedFile
    {
        if ($state instanceof UploadedFile) {
            return $state;
        }

        if (is_array($state)) {
            foreach ($state as $candidate) {
                if ($candidate instanceof UploadedFile) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
