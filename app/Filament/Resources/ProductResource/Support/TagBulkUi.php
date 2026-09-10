<?php

namespace App\Filament\Resources\ProductResource\Support;

use App\Enums\TagBulkOperation;
use App\Services\Catalog\TagBulkAssignmentService;
use App\Services\Catalog\TagBulkMetrics;

class TagBulkUi
{
    public static function assignmentService(): TagBulkAssignmentService
    {
        return app(TagBulkAssignmentService::class);
    }

    public static function formatPreviewText(TagBulkMetrics $metrics): string
    {
        if ($metrics->operation === TagBulkOperation::Add) {
            return sprintf(
                'Вибрано товарів: %d, тегів: %d. Товарів зі змінами: %d, без змін: %d. Зв\'язків буде додано: %d, вже присутні: %d.',
                $metrics->selectedProductCount,
                $metrics->selectedTagCount,
                $metrics->changedProductCount,
                $metrics->unchangedProductCount,
                $metrics->changedLinkCount,
                $metrics->noOpLinkCount,
            );
        }

        return sprintf(
            'Вибрано товарів: %d, тегів: %d. Товарів зі змінами: %d, без змін: %d. Зв\'язків буде видалено: %d, вже відсутні: %d.',
            $metrics->selectedProductCount,
            $metrics->selectedTagCount,
            $metrics->changedProductCount,
            $metrics->unchangedProductCount,
            $metrics->changedLinkCount,
            $metrics->noOpLinkCount,
        );
    }

    public static function formatResultNotification(TagBulkMetrics $metrics): string
    {
        if ($metrics->operation === TagBulkOperation::Add) {
            return sprintf(
                'Додано %d зв\'язків для %d з %d товарів. %d зв\'язків уже були присутні.',
                $metrics->changedLinkCount,
                $metrics->changedProductCount,
                $metrics->selectedProductCount,
                $metrics->noOpLinkCount,
            );
        }

        return sprintf(
            'Видалено %d зв\'язків для %d з %d товарів. %d зв\'язків уже були відсутні.',
            $metrics->changedLinkCount,
            $metrics->changedProductCount,
            $metrics->selectedProductCount,
            $metrics->noOpLinkCount,
        );
    }
}
