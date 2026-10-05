<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Services\Catalog\MasterProductDraftCreator;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    public function getTitle(): string
    {
        return 'Новий товар';
    }

    public function getSubheading(): ?string
    {
        return 'Для чернетки достатньо заповнити лише «Назва». Решту даних можна додати пізніше.';
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(MasterProductDraftCreator::class)->create(
            app(WorkspaceContext::class)->current(),
            $data,
        );
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Чернетку збережено';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Зберегти чернетку');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Зберегти й додати ще товар');
    }

    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('edit', ['record' => $this->record]);
    }
}
