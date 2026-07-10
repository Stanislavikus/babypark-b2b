<?php

namespace App\Filament\Resources\PriceListResource\Support;

use App\Enums\PriceListStatus;
use App\Models\Contractor;
use App\Models\PriceList;
use Illuminate\Support\Collection;

class PriceListGuard
{
    /**
     * @return array{allowed: bool, title: string, body: ?string}
     */
    public static function deleteBlockReason(PriceList $record): array
    {
        if ($record->is_default) {
            return [
                'allowed' => false,
                'title' => 'Неможливо видалити список за замовчуванням',
                'body' => 'Спочатку призначте інший активний прайс-лист списком за замовчуванням.',
            ];
        }

        $contractors = self::assignedContractors($record);

        if ($contractors->isNotEmpty()) {
            return [
                'allowed' => false,
                'title' => 'Неможливо видалити прайс-лист',
                'body' => 'Цей список призначено клієнтам: '.$contractors->pluck('name')->join(', ').'.',
            ];
        }

        return [
            'allowed' => true,
            'title' => '',
            'body' => null,
        ];
    }

    /**
     * @return array{allowed: bool, title: string, body: ?string}
     */
    public static function deactivateBlockReason(PriceList $record): array
    {
        if ($record->is_default) {
            return [
                'allowed' => false,
                'title' => 'Неможливо деактивувати список за замовчуванням',
                'body' => 'Спочатку зробіть інший активний прайс-лист списком за замовчуванням.',
            ];
        }

        $contractors = self::assignedContractors($record);

        if ($contractors->isNotEmpty()) {
            return [
                'allowed' => false,
                'title' => 'Неможливо деактивувати прайс-лист',
                'body' => 'Цей список призначено клієнтам: '.$contractors->pluck('name')->join(', ').'.',
            ];
        }

        return [
            'allowed' => true,
            'title' => '',
            'body' => null,
        ];
    }

    public static function canDeactivateTo(PriceList $record, PriceListStatus|string $newStatus): bool
    {
        $status = $newStatus instanceof PriceListStatus
            ? $newStatus
            : PriceListStatus::from($newStatus);

        if ($status !== PriceListStatus::Inactive) {
            return true;
        }

        if ($record->status === PriceListStatus::Inactive) {
            return true;
        }

        return self::deactivateBlockReason($record)['allowed'];
    }

    /**
     * @return Collection<int, Contractor>
     */
    public static function assignedContractors(PriceList $record): Collection
    {
        return $record->contractors()
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
