<?php

namespace App\Filament\Resources\MediaAssetResource\Pages;

use App\Filament\Resources\MediaAssetResource;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\MediaAssetLibraryReadService;
use App\Services\Media\MediaAssetLifecycleService;
use App\Support\Media\Exceptions\MediaAssetLifecycleException;
use App\Support\Media\Exceptions\MediaIngestException;
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
            $this->deleteAssetAction(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label('Зберегти');
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof MediaAsset) {
            return $record;
        }

        $file = $this->replacementUpload($data['replacement_upload'] ?? null);
        $updatedRecord = $record;
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

            $usage = app(MediaAssetLibraryReadService::class)->freshUsageCounts($result->asset);
            $this->replacementPostSaveNotice = match (true) {
                ! $result->replaced => 'Зображення вже актуальне. Змін не внесено.',
                $usage['products'] > 0 => 'Asset використовується товарами. Запустіть Preview ще раз, щоб перевірити актуальне зображення перед передачею.',
                default => null,
            };
            data_set($this->data, 'replacement_upload', null);
            $updatedRecord = $result->asset;
        }

        $updatedRecord->fill([
            'internal_note' => filled($data['internal_note'] ?? null)
                ? trim((string) $data['internal_note'])
                : null,
        ])->save();

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

                $this->redirect(MediaAssetResource::getUrl('index'));
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

        $this->js("window.close(); setTimeout(() => { if (! window.closed) { window.location.href = {$fallback}; } }, 100);");

        return null;
    }
}
