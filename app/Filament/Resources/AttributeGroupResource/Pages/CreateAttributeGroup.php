<?php

namespace App\Filament\Resources\AttributeGroupResource\Pages;

use App\Filament\Resources\AttributeGroupResource;
use App\Models\User;
use App\Services\ProductStructure\ProductStructureMutationService;
use App\Support\Workspace\WorkspaceContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAttributeGroup extends CreateRecord
{
    protected static string $resource = AttributeGroupResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(ProductStructureMutationService::class)->createAttributeGroup(
            $actor,
            app(WorkspaceContext::class)->current(),
            (string) $data['code'],
            ['uk' => (string) $data['label_uk'], 'en' => (string) $data['label_en']],
            isset($data['description']) ? (string) $data['description'] : null,
        );
    }
}
