<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\BrandManager;
use App\Services\Media\OriginalImageIngestService;
use App\Support\Media\Exceptions\MediaIngestException;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close_page')
                ->label('Закрити')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->requiresConfirmation(fn (): bool => $this->hasUnsavedBrandChanges())
                ->modalHeading('Закрити без збереження?')
                ->modalDescription('Незбережені зміни бренду буде втрачено.')
                ->modalSubmitActionLabel('Закрити')
                ->action(function (): mixed {
                    $this->rememberData();

                    return $this->closeCurrentTab(BrandResource::getUrl('index'));
                }),
        ];
    }

    private function hasUnsavedBrandChanges(): bool
    {
        $currentHash = md5((string) str(json_encode($this->data, JSON_UNESCAPED_UNICODE))->replace('\\', ''));

        return $currentHash !== $this->savedDataHash;
    }

    private function closeCurrentTab(string $fallbackUrl): null
    {
        $fallback = Js::from($fallbackUrl);

        $this->js("window.close(); setTimeout(() => { if (! window.closed) { window.location.href = {$fallback}; } }, 100);");

        return null;
    }

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
