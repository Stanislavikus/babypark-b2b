<?php

namespace App\Filament\Resources\TagResource\Pages;

use App\Filament\Resources\TagResource;
use App\Services\Catalog\TagManager;
use App\Support\Workspace\WorkspaceContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTag extends CreateRecord
{
    protected static string $resource = TagResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(TagManager::class)->create(
            app(WorkspaceContext::class)->id(),
            $data['name'],
        );
    }
}
