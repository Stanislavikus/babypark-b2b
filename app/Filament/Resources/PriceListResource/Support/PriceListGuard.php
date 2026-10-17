<?php

namespace App\Filament\Resources\PriceListResource\Support;

use App\Enums\PriceListStatus;
use App\Models\Customer;
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

        $customers = self::assignedCustomers($record);

        if ($customers->isNotEmpty()) {
            return [
                'allowed' => false,
                'title' => 'Неможливо видалити прайс-лист',
                'body' => 'Цей список призначено клієнтам: '.$customers->pluck('name')->join(', ').'.',
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

        $customers = self::assignedCustomers($record);

        if ($customers->isNotEmpty()) {
            return [
                'allowed' => false,
                'title' => 'Неможливо деактивувати прайс-лист',
                'body' => 'Цей список призначено клієнтам: '.$customers->pluck('name')->join(', ').'.',
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
     * @return Collection<int, Customer>
     */
    public static function assignedCustomers(PriceList $record): Collection
    {
        return $record->customers()
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
