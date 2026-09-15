<?php

namespace App\Filament\Resources\AttributeGroupResource\Pages;

use App\Filament\Resources\AttributeGroupResource;
use App\Models\AttributeGroup;
use App\Models\User;
use App\Services\ProductStructure\ProductStructureMutationService;
use App\Support\Workspace\WorkspaceContext;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAttributeGroup extends EditRecord
{
    protected static string $resource = AttributeGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var AttributeGroup $record */
        $record = $this->record;
        $data['label_uk'] = (string) ($record->localized_labels['uk'] ?? '');
        $data['label_en'] = (string) ($record->localized_labels['en'] ?? '');

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(ProductStructureMutationService::class)->updateAttributeGroupPresentation(
            $actor,
            app(WorkspaceContext::class)->current(),
            $record,
            ['uk' => (string) $data['label_uk'], 'en' => (string) $data['label_en']],
            isset($data['description']) ? (string) $data['description'] : null,
        );
    }
}
