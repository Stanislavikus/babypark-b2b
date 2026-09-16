<?php

namespace App\Filament\Resources\ProductTypeResource\Pages;

use App\Filament\Resources\ProductTypeResource;
use App\Models\User;
use App\Services\ProductStructure\ProductStructureMutationService;
use App\Support\Workspace\WorkspaceContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProductType extends CreateRecord
{
    protected static string $resource = ProductTypeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $workspace = app(WorkspaceContext::class)->current();

        return app(ProductStructureMutationService::class)->createProductType(
            $actor,
            $workspace,
            (string) $data['code'],
            [
                'uk' => (string) $data['label_uk'],
                'en' => (string) $data['label_en'],
            ],
            isset($data['description']) ? (string) $data['description'] : null,
        );
    }
}
