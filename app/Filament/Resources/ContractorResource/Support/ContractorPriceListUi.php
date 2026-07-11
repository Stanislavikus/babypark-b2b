<?php

namespace App\Filament\Resources\ContractorResource\Support;

use App\Models\Contractor;
use App\Models\PriceList;
use App\Services\Pricing\AssignmentPreview;
use App\Services\Pricing\AssignmentResult;
use App\Services\Pricing\ContractorPriceListAssignmentDisplay;
use App\Services\Pricing\ContractorPriceListAssignmentService;

class ContractorPriceListUi
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

    public static function resolveDisplay(Contractor $contractor): ContractorPriceListAssignmentDisplay
    {
        return ContractorPriceListAssignmentDisplay::resolve($contractor);
    }

    public static function assignmentService(): ContractorPriceListAssignmentService
    {
        return app(ContractorPriceListAssignmentService::class);
    }
}
