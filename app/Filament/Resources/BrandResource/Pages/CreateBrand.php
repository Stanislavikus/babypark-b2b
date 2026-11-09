<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\BrandManager;
use App\Services\Media\OriginalImageIngestService;
use App\Support\Media\Exceptions\MediaIngestException;
use App\Support\Workspace\WorkspaceContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateBrand extends CreateRecord
{
    protected static string $resource = BrandResource::class;

    protected function handleRecordCreation(array $data): Model
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

                return app(BrandManager::class)->create(
                    $actor,
                    $workspace,
                    (string) $data['name'],
                    $data['short_description'] ?? null,
                    (bool) ($data['is_active'] ?? true),
                    filled($data['logo_media_asset_id'] ?? null) ? (string) $data['logo_media_asset_id'] : null,
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

            if ($exception instanceof ValidationException) {
                throw $this->formValidationException($exception);
            }

            throw $exception;
        }
    }

    private function formValidationException(ValidationException $exception): ValidationException
    {
        $messages = [];

        foreach ($exception->errors() as $field => $fieldMessages) {
            $messages[str_starts_with($field, 'data.') ? $field : 'data.'.$field] = $fieldMessages;
        }

        return ValidationException::withMessages($messages);
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
