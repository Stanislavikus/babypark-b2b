<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use App\Models\User;
use App\Services\Catalog\BrandManager;
use App\Support\Workspace\WorkspaceContext;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return app(BrandManager::class)->update(
            $actor,
            app(WorkspaceContext::class)->current(),
            $record,
            $data,
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
