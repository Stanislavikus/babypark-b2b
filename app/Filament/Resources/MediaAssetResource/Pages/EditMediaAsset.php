<?php

namespace App\Filament\Resources\MediaAssetResource\Pages;

use App\Filament\Resources\MediaAssetResource;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\MediaAssetLibraryReadService;
use App\Services\Media\MediaAssetLifecycleService;
use App\Support\Media\Exceptions\MediaAssetLifecycleException;
use App\Support\Media\Exceptions\MediaIngestException;
use App\Support\Media\MediaSaveNotice;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Js;
use Illuminate\Validation\ValidationException;

class EditMediaAsset extends EditRecord
{
    protected static string $resource = MediaAssetResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    public ?string $replacementBusinessWarning = null;

    public ?string $replacementPostSaveNotice = null;

    public function getTitle(): string
    {
        return MediaAssetResource::displayName($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close_page')
                ->label('Закрити')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->requiresConfirmation(fn (): bool => $this->hasUnsavedAssetChanges())
                ->modalHidden(fn (): bool => ! $this->hasUnsavedAssetChanges())
                ->modalHeading('Закрити без збереження?')
                ->modalDescription('Незбережені зміни не буде збережено.')
                ->modalSubmitActionLabel('Закрити')
                ->action(function (): mixed {
                    $this->rememberData();

                    return $this->closeCurrentTab(MediaAssetResource::getUrl('index'));
                }),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return Action::make('save_media_with_confirmation')
            ->label('Зберегти')
            ->action(fn () => $this->save())
            ->keyBindings(['mod+s'])
            ->requiresConfirmation(fn (): bool => $this->requiresSharedImageConfirmation())
            ->modalHidden(fn (): bool => ! $this->requiresSharedImageConfirmation())
            ->modalHeading('Змінити спільне зображення?')
            ->modalDescription(fn (): string => $this->sharedImageConfirmationDescription())
            ->modalSubmitActionLabel('Замінити');
    }

    private function requiresSharedImageConfirmation(): bool
    {
        if (! $this->record instanceof MediaAsset) {
            return false;
        }

        $upload = $this->replacementUpload(data_get($this->data, 'replacement_upload'));

        if (! $upload instanceof UploadedFile) {
            return false;
        }

        $currentHash = $this->record->content_sha256;
        $path = $upload->getRealPath();

        if (is_string($currentHash) && is_string($path) && $path !== ''
            && hash_equals($currentHash, (string) hash_file('sha256', $path))) {
            return false;
        }

        $usage = app(MediaAssetLibraryReadService::class)->freshUsageCounts($this->record);

        return $usage['products'] + $usage['variants'] + $usage['brands'] > 1;
    }

    private function sharedImageConfirmationDescription(): string
    {
        $usage = app(MediaAssetLibraryReadService::class)->freshUsageCounts($this->record);
        $parts = [];

        foreach (['products' => 'товарів', 'variants' => 'варіантів', 'brands' => 'брендів'] as $key => $label) {
            if ($usage[$key] > 0) {
                $parts[] = $label.': '.$usage[$key];
            }
        }

        return 'Це зображення використовується в кількох об’єктах ('.implode(' · ', $parts)
            .'). Після заміни нове зображення відображатиметься в усіх цих об’єктах.';
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            $this->getCancelFormAction(),
            $this->deleteAssetAction()
                ->extraAttributes(['class' => 'ms-auto']),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof MediaAsset) {
            return $record;
        }

        $file = $this->replacementUpload($data['replacement_upload'] ?? null);
        $previousNote = filled($record->internal_note) ? trim((string) $record->internal_note) : null;
        $updatedRecord = $record;
        $imageUnchanged = false;
        $postSaveUsage = null;
        $this->replacementBusinessWarning = null;
        $this->replacementPostSaveNotice = null;

        if ($file instanceof UploadedFile) {
            $actor = auth()->user();

            if (! $actor instanceof User) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            try {
                $result = app(MediaAssetLifecycleService::class)->replaceOriginal(
                    $actor,
                    app(WorkspaceContext::class)->current(),
                    $record,
                    $file,
                );
            } catch (MediaAssetLifecycleException $exception) {
                $this->replacementBusinessWarning = $exception->getMessage();

                throw (new Halt)->rollBackDatabaseTransaction();
            } catch (MediaIngestException $exception) {
                throw ValidationException::withMessages([
                    'data.replacement_upload' => $exception->getMessage(),
                ]);
            }

            $postSaveUsage = app(MediaAssetLibraryReadService::class)->freshUsageCounts($result->asset);
            $imageUnchanged = ! $result->replaced;
            data_set($this->data, 'replacement_upload', null);
            $record->setRawAttributes($result->asset->getAttributes(), true);
            $record->unsetRelations();
            $updatedRecord = $record;
        }

        $updatedRecord->fill([
            'internal_note' => filled($data['internal_note'] ?? null)
                ? trim((string) $data['internal_note'])
                : null,
        ])->save();

        if ($file instanceof UploadedFile) {
            $nextNote = filled($updatedRecord->internal_note) ? trim((string) $updatedRecord->internal_note) : null;

            $this->replacementPostSaveNotice = match (true) {
                $imageUnchanged => MediaSaveNotice::unchanged($previousNote !== $nextNote),
                ($postSaveUsage['products'] ?? 0) > 0 => 'Asset використовується товарами. Запустіть Preview ще раз, щоб перевірити актуальне зображення перед передачею.',
                default => null,
            };
        }

        $this->record = $updatedRecord;

        return $updatedRecord;
    }

    protected function getSavedNotification(): ?Notification
    {
        return null;
    }

    protected function getRedirectUrl(): ?string
    {
        return null;
    }

    private function deleteAssetAction(): Action
    {
        return Action::make('delete_asset')
            ->label('Видалити')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Видалити Asset?')
            ->modalDescription(fn (): string => $this->deleteDescription())
            ->modalSubmitActionLabel('Видалити')
            ->action(function (Action $action): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new AuthorizationException('This action is unauthorized.');
                }

                try {
                    app(MediaAssetLifecycleService::class)->deleteUnusedOriginal(
                        $actor,
                        app(WorkspaceContext::class)->current(),
                        $this->record,
                    );
                } catch (MediaAssetLifecycleException $exception) {
                    Notification::make()
                        ->warning()
                        ->title('Asset не видалено')
                        ->body($exception->getMessage())
                        ->duration(8000)
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Asset видалено')
                    ->send();

                $this->closeCurrentTab(MediaAssetResource::getUrl('index'));
            });
    }

    private function deleteDescription(): string
    {
        $usage = app(MediaAssetLibraryReadService::class)->freshUsageCounts($this->record);

        if (array_sum($usage) > 0) {
            return 'Asset зараз використовується і не буде видалений. '.$this->usageSummary($usage).'. Спочатку приберіть або перепризначте ці використання.';
        }

        return 'Asset буде фізично видалено з Master. Керований Original-файл буде очищено після успішної фіксації змін у базі даних.';
    }

    /** @param array{products:int,variants:int,brands:int,derivatives:int} $usage */
    private function usageSummary(array $usage): string
    {
        return sprintf(
            'Товарів: %d · Варіантів: %d · Брендів: %d · Похідних версій: %d',
            $usage['products'],
            $usage['variants'],
            $usage['brands'],
            $usage['derivatives'],
        );
    }

    private function replacementUpload(mixed $state = null): ?UploadedFile
    {
        $state ??= data_get($this->data, 'replacement_upload');
        $file = Arr::first(Arr::wrap($state));

        return $file instanceof UploadedFile ? $file : null;
    }

    private function hasUnsavedAssetChanges(): bool
    {
        if ($this->replacementUpload() instanceof UploadedFile) {
            return true;
        }

        $currentNote = filled(data_get($this->data, 'internal_note'))
            ? trim((string) data_get($this->data, 'internal_note'))
            : null;
        $savedNote = filled($this->record->internal_note)
            ? trim((string) $this->record->internal_note)
            : null;

        return $currentNote !== $savedNote;
    }

    private function closeCurrentTab(string $fallbackUrl): null
    {
        $fallback = Js::from($fallbackUrl);
        $refreshKey = Js::from(MediaAssetResource::LIST_REFRESH_STORAGE_KEY);

        $this->unmountAction();
        $this->js("(() => { try { \$wire.savedDataHash = window.jsMd5(JSON.stringify(\$wire.data).split(String.fromCharCode(92)).join('')); } catch (e) {} try { localStorage.setItem({$refreshKey}, String(Date.now()) + ':' + String(Math.random())); } catch (e) {} window.close(); setTimeout(() => { if (! window.closed) { window.location.href = {$fallback}; } }, 100); })();");

        return null;
    }
}
