<?php

namespace App\Filament\Resources\ProductTypeResource\Pages;

use App\Filament\Resources\ProductTypeResource;
use App\Models\ProductType;
use App\Models\User;
use App\Services\ProductStructure\ProductStructureMutationService;
use App\Support\Workspace\WorkspaceContext;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProductType extends EditRecord
{
    protected static string $resource = ProductTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ProductType $record */
        $record = $this->record;
        $data['label_uk'] = (string) ($record->localized_labels['uk'] ?? '');
        $data['label_en'] = (string) ($record->localized_labels['en'] ?? '');

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(ProductStructureMutationService::class)->updateProductTypePresentation(
            $actor,
            app(WorkspaceContext::class)->current(),
            $record,
            ['uk' => (string) $data['label_uk'], 'en' => (string) $data['label_en']],
            isset($data['description']) ? (string) $data['description'] : null,
        );
    }
}
