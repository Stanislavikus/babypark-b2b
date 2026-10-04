<?php

namespace App\Services\Availability;

use App\Models\ProductVariant;

final class MasterInventoryReadService
{
    public function __construct(
        private readonly AvailabilityResolver $availabilityResolver,
    ) {}

    /**
     * @return array{
     *   editable:bool,
     *   state:string,
     *   current_quantity:int,
     *   net_available:int,
     *   pending_quantity:int,
     *   message:string
     * }
     */
    public function state(ProductVariant $variant): array
    {
        $variant->loadMissing('stocks');

        $cache = (int) $variant->available_quantity_cache;
        $pending = $this->availabilityResolver->activePendingReservationsSum($variant);
        $net = max(0, $cache - $pending);

        if (filled($variant->onec_guid)) {
            return $this->result(
                editable: false,
                state: 'source_owned_read_only',
                currentQuantity: $cache,
                netAvailable: $net,
                pendingQuantity: $pending,
                message: 'Для варіанта з джерелом 1С залишок у Master доступний лише для перегляду.',
            );
        }

        $stocks = $variant->stocks->values();

        if ($stocks->isEmpty()) {
            if ($cache !== 0) {
                return $this->result(
                    editable: false,
                    state: 'reconciliation_required',
                    currentQuantity: $cache,
                    netAvailable: $net,
                    pendingQuantity: $pending,
                    message: 'Залишок потребує звірки перед редагуванням.',
                );
            }

            return $this->result(
                editable: true,
                state: 'ready_to_initialize',
                currentQuantity: 0,
                netAvailable: $net,
                pendingQuantity: $pending,
                message: 'Перший залишок буде створено автоматично.',
            );
        }

        if ($stocks->count() > 1) {
            return $this->result(
                editable: false,
                state: 'multiple_locations_read_only',
                currentQuantity: $cache,
                netAvailable: $net,
                pendingQuantity: $pending,
                message: 'Для кількох місць зберігання редагування в Master поки недоступне.',
            );
        }

        $stockQuantity = (int) $stocks->sole()->quantity;

        if ($stockQuantity !== $cache) {
            return $this->result(
                editable: false,
                state: 'reconciliation_required',
                currentQuantity: $cache,
                netAvailable: $net,
                pendingQuantity: $pending,
                message: 'Залишок потребує звірки перед редагуванням.',
            );
        }

        return $this->result(
            editable: true,
            state: 'editable',
            currentQuantity: $stockQuantity,
            netAvailable: $net,
            pendingQuantity: $pending,
            message: 'Залишок можна редагувати.',
        );
    }

    /**
     * @return array{
     *   editable:bool,
     *   state:string,
     *   current_quantity:int,
     *   net_available:int,
     *   pending_quantity:int,
     *   message:string
     * }
     */
    private function result(
        bool $editable,
        string $state,
        int $currentQuantity,
        int $netAvailable,
        int $pendingQuantity,
        string $message,
    ): array {
        return [
            'editable' => $editable,
            'state' => $state,
            'current_quantity' => $currentQuantity,
            'net_available' => $netAvailable,
            'pending_quantity' => $pendingQuantity,
            'message' => $message,
        ];
    }
}
