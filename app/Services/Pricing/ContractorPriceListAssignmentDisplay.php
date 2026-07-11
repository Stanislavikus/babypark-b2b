<?php

namespace App\Services\Pricing;

use App\Enums\PriceListStatus;
use App\Models\Contractor;
use App\Models\PriceList;

enum ContractorPriceListAssignmentDisplayState: string
{
    case InheritedDefault = 'inherited_default';
    case ActiveDirect = 'active_direct';
    case InactiveHistorical = 'inactive_historical';
    case RedundantDirect = 'redundant_direct';
}

final class ContractorPriceListAssignmentDisplay
{
    public function __construct(
        public readonly ContractorPriceListAssignmentDisplayState $state,
        public readonly ?PriceList $assignedList,
        public readonly ?PriceList $workspaceDefaultList,
    ) {}

    public static function resolve(Contractor $contractor, ?PriceList $workspaceDefault = null): self
    {
        $workspaceDefault ??= self::workspaceDefaultList($contractor->workspace_id);
        $assignedList = null;

        if ($contractor->default_price_list_id !== null) {
            $assignedList = $contractor->relationLoaded('defaultPriceList')
                ? $contractor->defaultPriceList
                : PriceList::withoutWorkspaceScope()->find($contractor->default_price_list_id);
        }

        if ($assignedList === null) {
            return new self(
                ContractorPriceListAssignmentDisplayState::InheritedDefault,
                null,
                $workspaceDefault,
            );
        }

        if ($assignedList->status !== PriceListStatus::Active) {
            return new self(
                ContractorPriceListAssignmentDisplayState::InactiveHistorical,
                $assignedList,
                $workspaceDefault,
            );
        }

        if ($workspaceDefault !== null && $assignedList->id === $workspaceDefault->id) {
            return new self(
                ContractorPriceListAssignmentDisplayState::RedundantDirect,
                $assignedList,
                $workspaceDefault,
            );
        }

        return new self(
            ContractorPriceListAssignmentDisplayState::ActiveDirect,
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
            ContractorPriceListAssignmentDisplayState::InheritedDefault => 'Основний прайс компанії',
            ContractorPriceListAssignmentDisplayState::ActiveDirect => $this->assignedList?->name ?? '—',
            ContractorPriceListAssignmentDisplayState::InactiveHistorical => ($this->assignedList?->name ?? '—').' · Неактивний',
            ContractorPriceListAssignmentDisplayState::RedundantDirect => ($this->assignedList?->name ?? '—').' · основний прайс',
        };
    }

    public function tableDescription(): ?string
    {
        return match ($this->state) {
            ContractorPriceListAssignmentDisplayState::InactiveHistorical => 'використовується основний прайс',
            ContractorPriceListAssignmentDisplayState::RedundantDirect => 'рекомендується «За замовчуванням»',
            default => null,
        };
    }

    public function infolistLabel(): string
    {
        return match ($this->state) {
            ContractorPriceListAssignmentDisplayState::InheritedDefault => 'Основний прайс компанії',
            ContractorPriceListAssignmentDisplayState::ActiveDirect => $this->assignedList?->name ?? '—',
            ContractorPriceListAssignmentDisplayState::InactiveHistorical => ($this->assignedList?->name ?? '—').' — неактивний',
            ContractorPriceListAssignmentDisplayState::RedundantDirect => '«'.($this->assignedList?->name ?? '—').'» — зараз це основний прайс-лист компанії',
        };
    }

    public function infolistDescription(): ?string
    {
        return match ($this->state) {
            ContractorPriceListAssignmentDisplayState::InheritedDefault => $this->inheritedHelperText(),
            ContractorPriceListAssignmentDisplayState::ActiveDirect => $this->assignedActiveHelperText(),
            ContractorPriceListAssignmentDisplayState::InactiveHistorical => 'Фактично використовується основний прайс-лист компанії, а за відсутності ціни — базова ціна товару.',
            ContractorPriceListAssignmentDisplayState::RedundantDirect => 'Рекомендується використовувати «За замовчуванням», щоб автоматично слідувати за основним прайс-листом компанії.',
            default => null,
        };
    }

    public function historicalSelectLabel(): ?string
    {
        if ($this->assignedList === null) {
            return null;
        }

        return match ($this->state) {
            ContractorPriceListAssignmentDisplayState::InactiveHistorical => '«'.$this->assignedList->name.'» — неактивний. Фактично використовується основний прайс-лист компанії.',
            ContractorPriceListAssignmentDisplayState::RedundantDirect => '«'.$this->assignedList->name.'» — зараз це основний прайс-лист компанії. Рекомендується використовувати «За замовчуванням».',
            default => null,
        };
    }

    public function formWarning(): ?string
    {
        return match ($this->state) {
            ContractorPriceListAssignmentDisplayState::InactiveHistorical => 'Призначений прайс-лист неактивний. Фактично використовується основний прайс-лист компанії.',
            ContractorPriceListAssignmentDisplayState::RedundantDirect => 'Цей прайс-лист зараз є основним для компанії. Рекомендується обрати «За замовчуванням».',
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
