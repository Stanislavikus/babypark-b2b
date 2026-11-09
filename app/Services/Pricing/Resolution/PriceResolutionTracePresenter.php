<?php

namespace App\Services\Pricing\Resolution;

final class PriceResolutionTracePresenter
{
    public function present(PriceResolutionResult $result): string
    {
        $lines = [];
        $lines[] = 'Status: '.$result->status->value;
        $lines[] = 'Reason codes: '.implode(', ', array_map(
            fn (PriceResolutionReason $reason) => $reason->value,
            $result->reasonCodes,
        ));

        if ($result->price !== null) {
            $lines[] = sprintf(
                'Price: %.2f %s (gross %.2f, source %s)',
                $result->price->effectiveNetPrice,
                $result->price->currency,
                $result->price->grossPrice,
                $result->price->source,
            );
        }

        if ($result->failure !== null) {
            $lines[] = 'Failure: '.$result->failure->reason->value.' — '.$result->failure->message;
            if ($result->failure->context !== []) {
                $lines[] = 'Context: '.json_encode($result->failure->context, JSON_UNESCAPED_UNICODE);
            }
        }

        $lines[] = 'Trace:';

        foreach ($result->trace->steps as $index => $step) {
            $lines[] = sprintf(
                '  %d. %s / %s / %s',
                $index + 1,
                $step->source->value,
                $step->status->value,
                $step->reason->value,
            );

            if ($step->priceListId !== null) {
                $lines[] = '     price_list_id: '.$step->priceListId;
            }

            if ($step->priceListItemId !== null) {
                $lines[] = '     price_list_item_id: '.$step->priceListItemId;
            }

            if ($step->amount !== null) {
                $lines[] = sprintf('     amount: %.2f %s', $step->amount, $step->currency ?? '');
            }

            if ($step->metadata !== []) {
                $lines[] = '     metadata: '.json_encode($step->metadata, JSON_UNESCAPED_UNICODE);
            }
        }

        return implode("\n", $lines);
    }

    public function reasonLabel(PriceResolutionReason $reason): string
    {
        return match ($reason) {
            PriceResolutionReason::PriceListNotAssigned => 'Прайс-лист не призначено',
            PriceResolutionReason::PriceListInactive => 'Прайс-лист неактивний',
            PriceResolutionReason::ItemMissing => 'Позицію не знайдено',
            PriceResolutionReason::ItemInactive => 'Позиція неактивна',
            PriceResolutionReason::QuantityBelowMinimum => 'Кількість нижче мінімуму',
            PriceResolutionReason::NotYetEffective => 'Ще не набула чинності',
            PriceResolutionReason::Expired => 'Термін дії минув',
            PriceResolutionReason::Matched => 'Збіг',
            PriceResolutionReason::PreviousSourceResolved => 'Попереднє джерело вже розв’язало ціну',
            PriceResolutionReason::AllSourcesExhausted => 'Усі джерела вичерпано',
            PriceResolutionReason::DefaultPriceListMisconfigured => 'Дефолтний прайс-лист налаштовано некоректно',
        };
    }

    public function sourceLabel(PriceResolutionSource $source): string
    {
        return match ($source) {
            PriceResolutionSource::CustomerPriceList => 'Прайс-лист клієнта',
            PriceResolutionSource::WorkspaceDefaultPriceList => 'Дефолтний прайс-лист workspace',
            PriceResolutionSource::BasePriceCache => 'Базовий кеш ціни',
        };
    }

    public function stepStatusLabel(PriceResolutionStepStatus $status): string
    {
        return match ($status) {
            PriceResolutionStepStatus::Matched => 'Збіг',
            PriceResolutionStepStatus::Skipped => 'Пропущено',
            PriceResolutionStepStatus::NotChecked => 'Не перевірялось',
            PriceResolutionStepStatus::Failed => 'Помилка',
        };
    }
}
