<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use App\Exceptions\Pricing\InvalidPriceListAssignmentException;
use App\Filament\Resources\CustomerResource;
use App\Models\PriceList;
use App\Services\Pricing\CustomerPriceListAssignmentService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        $record = $this->getRecord();
        $originalTargetId = $record->getOriginal('default_price_list_id');

        if ($originalTargetId !== null) {
            $originalList = PriceList::withoutWorkspaceScope()->find($originalTargetId);

            if ($originalList !== null && $originalList->workspace_id !== $record->workspace_id) {
                throw ValidationException::withMessages([
                    'default_price_list_id' => InvalidPriceListAssignmentException::crossWorkspace()->getMessage(),
                ]);
            }
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();
        $originalTargetId = $record->getOriginal('default_price_list_id');
        $submittedTargetId = $data['default_price_list_id'] ?? null;

        if ($submittedTargetId !== $originalTargetId) {
            try {
                app(CustomerPriceListAssignmentService::class)
                    ->validateTarget($record->workspace_id, $submittedTargetId);
            } catch (InvalidPriceListAssignmentException $exception) {
                throw ValidationException::withMessages([
                    'default_price_list_id' => $exception->getMessage(),
                ]);
            }
        }

        return $data;
    }
}
