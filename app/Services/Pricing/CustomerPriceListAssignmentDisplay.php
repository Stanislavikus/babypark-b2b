<?php

namespace App\Services\Pricing;

use App\Enums\PriceListStatus;
use App\Models\Customer;
use App\Models\PriceList;

enum CustomerPriceListAssignmentDisplayState: string
{
    case InheritedDefault = 'inherited_default';
    case ActiveDirect = 'active_direct';
    case InactiveHistorical = 'inactive_historical';
    case RedundantDirect = 'redundant_direct';
}

final class CustomerPriceListAssignmentDisplay
{
    public function __construct(
        public readonly CustomerPriceListAssignmentDisplayState $state,
        public readonly ?PriceList $assignedList,
        public readonly ?PriceList $workspaceDefaultList,
    ) {}

    public static function resolve(Customer $customer, ?PriceList $workspaceDefault = null): self
    {
        $workspaceDefault ??= self::workspaceDefaultList($customer->workspace_id);
        $assignedList = null;

        if ($customer->default_price_list_id !== null) {
            $assignedList = $customer->relationLoaded('defaultPriceList')
                ? $customer->defaultPriceList
                : PriceList::withoutWorkspaceScope()->find($customer->default_price_list_id);
        }

        if ($assignedList === null) {
            return new self(
                CustomerPriceListAssignmentDisplayState::InheritedDefault,
                null,
                $workspaceDefault,
            );
        }

        if ($assignedList->status !== PriceListStatus::Active) {
            return new self(
                CustomerPriceListAssignmentDisplayState::InactiveHistorical,
                $assignedList,
                $workspaceDefault,
            );
        }

        if ($workspaceDefault !== null && $assignedList->id === $workspaceDefault->id) {
            return new self(
                CustomerPriceListAssignmentDisplayState::RedundantDirect,
                $assignedList,
                $workspaceDefault,
            );
        }

        return new self(
            CustomerPriceListAssignmentDisplayState::ActiveDirect,
            $assignedList,
            $workspaceDefault,
        );
    }

    public static function workspaceDefaultList(string $workspaceId): ?PriceList
    {
        static $cache = [];

        if (array_key_exists($workspaceId, $cache)) {
            return $cache[$workspaceId];
        }

        return $cache[$workspaceId] = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('is_default', true)
            ->where('status', PriceListStatus::Active)
            ->first();
    }

    public function tableLabel(): string
    {
        return match ($this->state) {
            CustomerPriceListAssignmentDisplayState::InheritedDefault => 'Основний прайс компанії',
            CustomerPriceListAssignmentDisplayState::ActiveDirect => $this->assignedList?->name ?? '—',
            CustomerPriceListAssignmentDisplayState::InactiveHistorical => ($this->assignedList?->name ?? '—').' · Неактивний',
            CustomerPriceListAssignmentDisplayState::RedundantDirect => ($this->assignedList?->name ?? '—').' · основний прайс',
        };
    }

    public function tableDescription(): ?string
    {
        return match ($this->state) {
            CustomerPriceListAssignmentDisplayState::InactiveHistorical => 'використовується основний прайс',
            CustomerPriceListAssignmentDisplayState::RedundantDirect => 'рекомендується «За замовчуванням»',
            default => null,
        };
    }

    public function infolistLabel(): string
    {
        return match ($this->state) {
            CustomerPriceListAssignmentDisplayState::InheritedDefault => 'Основний прайс компанії',
            CustomerPriceListAssignmentDisplayState::ActiveDirect => $this->assignedList?->name ?? '—',
            CustomerPriceListAssignmentDisplayState::InactiveHistorical => ($this->assignedList?->name ?? '—').' — неактивний',
            CustomerPriceListAssignmentDisplayState::RedundantDirect => '«'.($this->assignedList?->name ?? '—').'» — зараз це основний прайс-лист компанії',
        };
    }

    public function infolistDescription(): ?string
    {
        return match ($this->state) {
            CustomerPriceListAssignmentDisplayState::InheritedDefault => $this->inheritedHelperText(),
            CustomerPriceListAssignmentDisplayState::ActiveDirect => $this->assignedActiveHelperText(),
            CustomerPriceListAssignmentDisplayState::InactiveHistorical => 'Фактично використовується основний прайс-лист компанії, а за відсутності ціни — базова ціна товару.',
            CustomerPriceListAssignmentDisplayState::RedundantDirect => 'Рекомендується використовувати «За замовчуванням», щоб автоматично слідувати за основним прайс-листом компанії.',
            default => null,
        };
    }

    public function historicalSelectLabel(): ?string
    {
        if ($this->assignedList === null) {
            return null;
        }

        return match ($this->state) {
            CustomerPriceListAssignmentDisplayState::InactiveHistorical => '«'.$this->assignedList->name.'» — неактивний. Фактично використовується основний прайс-лист компанії.',
            CustomerPriceListAssignmentDisplayState::RedundantDirect => '«'.$this->assignedList->name.'» — зараз це основний прайс-лист компанії. Рекомендується використовувати «За замовчуванням».',
            default => null,
        };
    }

    public function formWarning(): ?string
    {
        return match ($this->state) {
            CustomerPriceListAssignmentDisplayState::InactiveHistorical => 'Призначений прайс-лист неактивний. Фактично використовується основний прайс-лист компанії.',
            CustomerPriceListAssignmentDisplayState::RedundantDirect => 'Цей прайс-лист зараз є основним для компанії. Рекомендується обрати «За замовчуванням».',
            default => null,
        };
    }

    public function formHelperText(?string $selectedValue): string
    {
        if ($selectedValue === null || $selectedValue === '') {
            return $this->inheritedHelperText();
        }

        $list = PriceList::withoutWorkspaceScope()->find($selectedValue);

        if ($list === null) {
            return $this->inheritedHelperText();
        }

        if ($list->status !== PriceListStatus::Active) {
            return 'Фактично використовується основний прайс-лист компанії, а за відсутності ціни — базова ціна товару.';
        }

        return $this->assignedActiveHelperText($list->name);
    }

    private function inheritedHelperText(): string
    {
        return 'Індивідуальний прайс-лист не призначено. Використовується основний прайс-лист компанії, а за відсутності ціни — базова ціна товару.';
    }

    private function assignedActiveHelperText(?string $listName = null): string
    {
        $name = $listName ?? $this->assignedList?->name ?? 'призначений';

        return "Призначений прайс-лист: «{$name}». Якщо в ньому немає активної ціни для товару, система перевіряє основний прайс-лист компанії. Якщо ціни немає і там — використовується базова ціна товару.";
    }
}
