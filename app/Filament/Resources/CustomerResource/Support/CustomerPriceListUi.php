<?php

namespace App\Filament\Resources\CustomerResource\Support;

use App\Models\Customer;
use App\Models\PriceList;
use App\Services\Pricing\AssignmentPreview;
use App\Services\Pricing\AssignmentResult;
use App\Services\Pricing\CustomerPriceListAssignmentDisplay;
use App\Services\Pricing\CustomerPriceListAssignmentService;

class CustomerPriceListUi
{
    public static function formatPreviewText(AssignmentPreview $preview, ?string $targetPriceListId): string
    {
        if ($targetPriceListId === null) {
            return sprintf(
                'Вибрано клієнтів: %d. Індивідуальний прайс-лист буде скасовано для %d клієнтів. %d клієнтів уже використовують основний прайс-лист.',
                $preview->selectedCount,
                $preview->clearedCount,
                $preview->unchangedCount,
            );
        }

        return sprintf(
            'Вибрано клієнтів: %d. Буде змінено: %d. Вже використовують цей прайс-лист: %d.',
            $preview->selectedCount,
            $preview->changedCount,
            $preview->unchangedCount,
        );
    }

    public static function formatResultNotification(AssignmentResult $result, ?string $targetPriceListId): string
    {
        if ($targetPriceListId === null) {
            return sprintf(
                'Оновлено %d з %d клієнтів. Скасовано індивідуальний прайс-лист для %d клієнтів.',
                $result->updatedCount,
                $result->selectedCount,
                $result->clearedCount,
            );
        }

        $listName = PriceList::withoutWorkspaceScope()->find($targetPriceListId)?->name ?? 'прайс-лист';

        return sprintf(
            'Оновлено %d з %d клієнтів. Призначено прайс-лист «%s».',
            $result->updatedCount,
            $result->selectedCount,
            $listName,
        );
    }

    public static function resolveDisplay(Customer $customer): CustomerPriceListAssignmentDisplay
    {
        return CustomerPriceListAssignmentDisplay::resolve($customer);
    }

    public static function assignmentService(): CustomerPriceListAssignmentService
    {
        return app(CustomerPriceListAssignmentService::class);
    }
}
