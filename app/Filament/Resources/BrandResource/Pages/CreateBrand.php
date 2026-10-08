<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use App\Models\User;
use App\Services\Catalog\BrandManager;
use App\Services\Media\OriginalImageIngestService;
use App\Support\Media\Exceptions\MediaIngestException;
use App\Support\Workspace\WorkspaceContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

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

        if ($upload instanceof UploadedFile) {
            try {
                $asset = app(OriginalImageIngestService::class)
                    ->ingestStandalone($actor, $workspace, $upload);
            } catch (MediaIngestException $exception) {
                throw ValidationException::withMessages([
                    'data.logo_upload' => $exception->getMessage(),
                ]);
            }

            $data['logo_media_asset_id'] = (string) $asset->id;
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
