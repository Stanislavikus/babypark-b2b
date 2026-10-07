<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use App\Models\User;
use App\Services\Catalog\BrandManager;
use App\Support\Workspace\WorkspaceContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CreateBrand extends CreateRecord
{
    protected static string $resource = BrandResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return app(BrandManager::class)->create(
            $actor,
            app(WorkspaceContext::class)->current(),
            (string) $data['name'],
            $data['short_description'] ?? null,
            (bool) ($data['is_active'] ?? true),
            filled($data['logo_media_asset_id'] ?? null) ? (string) $data['logo_media_asset_id'] : null,
        );
    }
}
