<?php

namespace App\Support\Sync\Preview\Presentation;

use App\Filament\Pages\Sync\ManageAdobeProductsExportSetup;
use App\Filament\Pages\Sync\ManageSyncFieldMappings;
use App\Filament\Resources\ProductResource;
use App\Models\FieldBinding;
use App\Models\ProductVariant;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;

final readonly class SyncPreviewPresentationContext
{
    /**
     * @param  array<string, FieldBinding>  $bindingsById
     * @param  array<string, array{external_field_key: string, option_mappings: list<array<string, mixed>>}>  $historicalMappingsByBindingId
     * @param  array<string, array{external_field_key: string, option_mappings: list<array<string, mixed>>}>  $currentMappingsByBindingId
     * @param  array<string, ProductVariant>  $variantsById
     */
    public function __construct(
        public User $actor,
        public Workspace $workspace,
        public string $accountId,
        public ?SyncConfiguration $configuration,
        public array $configurationSnapshot,
        public array $bindingsById,
        public array $historicalMappingsByBindingId,
        public array $currentMappingsByBindingId,
        public array $variantsById,
        public bool $canManageSetup,
        public bool $canViewMappings,
        public bool $canManageMappings,
        public ?int $historicalAttributeSetId,
        public ?int $currentAttributeSetId,
    ) {}

    public function binding(?string $bindingId): ?FieldBinding
    {
        if ($bindingId === null || $bindingId === '') {
            return null;
        }

        return $this->bindingsById[$bindingId] ?? null;
    }

    public function variant(?string $variantId): ?ProductVariant
    {
        if ($variantId === null || $variantId === '') {
            return null;
        }

        return $this->variantsById[$variantId] ?? null;
    }

    public function mappingChanged(string $bindingId): bool
    {
        return $this->fieldMappingChanged($bindingId);
    }

    public function fieldMappingChanged(string $bindingId): bool
    {
        $historical = $this->historicalMappingsByBindingId[$bindingId] ?? null;
        $current = $this->currentMappingsByBindingId[$bindingId] ?? null;

        if ($historical === null && $current === null) {
            return false;
        }

        if ($historical === null || $current === null) {
            return true;
        }

        return $historical['external_field_key'] !== $current['external_field_key'];
    }

    public function optionMappingChanged(string $bindingId, ?string $internalOptionKey = null): bool
    {
        $historical = $this->historicalMappingsByBindingId[$bindingId] ?? null;
        $current = $this->currentMappingsByBindingId[$bindingId] ?? null;

        if ($historical === null && $current === null) {
            return false;
        }

        if ($historical === null || $current === null) {
            return true;
        }

        if ($historical['external_field_key'] !== $current['external_field_key']) {
            return true;
        }

        if ($internalOptionKey === null) {
            return $this->canonicalizeOptions($historical['option_mappings'])
                !== $this->canonicalizeOptions($current['option_mappings']);
        }

        $historicalOption = $this->optionMappingForKey($historical['option_mappings'], $internalOptionKey);
        $currentOption = $this->optionMappingForKey($current['option_mappings'], $internalOptionKey);

        return $historicalOption !== $currentOption;
    }

    public function externalOptionTargetChanged(
        string $bindingId,
        ?string $externalFieldKey,
        ?string $externalOptionValue,
    ): bool {
        if ($this->fieldMappingChanged($bindingId)) {
            return true;
        }

        $historical = $this->historicalMappingsByBindingId[$bindingId] ?? null;
        $current = $this->currentMappingsByBindingId[$bindingId] ?? null;

        if ($historical === null || $current === null) {
            return $historical !== $current;
        }

        if ($externalFieldKey !== null && $historical['external_field_key'] !== $externalFieldKey) {
            return true;
        }

        if ($externalOptionValue === null) {
            return false;
        }

        $historicalOptions = $this->canonicalizeOptions($historical['option_mappings']);
        $currentOptions = $this->canonicalizeOptions($current['option_mappings']);

        foreach ($historicalOptions as $option) {
            if ($option['external_option_value'] === $externalOptionValue) {
                $matchingCurrent = collect($currentOptions)->first(
                    fn (array $currentOption): bool => $currentOption['internal_option_key'] === $option['internal_option_key'],
                );

                if ($matchingCurrent === null) {
                    return true;
                }

                return $matchingCurrent['external_option_value'] !== $option['external_option_value'];
            }
        }

        return false;
    }

    public function mappingSetChanged(): bool
    {
        $historicalKeys = array_keys($this->historicalMappingsByBindingId);
        $currentKeys = array_keys($this->currentMappingsByBindingId);

        sort($historicalKeys);
        sort($currentKeys);

        if ($historicalKeys !== $currentKeys) {
            return true;
        }

        foreach ($historicalKeys as $bindingId) {
            if ($this->fullMappingPayloadChanged($bindingId)) {
                return true;
            }
        }

        return false;
    }

    public function fullMappingPayloadChanged(string $bindingId): bool
    {
        if ($this->fieldMappingChanged($bindingId)) {
            return true;
        }

        return $this->optionMappingChanged($bindingId);
    }

    public function connectorSetupChanged(): bool
    {
        if ($this->historicalAttributeSetId === null || $this->currentAttributeSetId === null) {
            return $this->historicalAttributeSetId !== $this->currentAttributeSetId;
        }

        return $this->historicalAttributeSetId !== $this->currentAttributeSetId;
    }

    public function fieldMappingUrl(): ?string
    {
        if ($this->configuration === null) {
            return null;
        }

        return ManageSyncFieldMappings::getUrl([
            'account' => $this->accountId,
            'configuration' => $this->configuration->id,
        ]);
    }

    public function connectorSetupUrl(): ?string
    {
        return ManageAdobeProductsExportSetup::getUrl(['account' => $this->accountId]);
    }

    public function productViewUrl(string $productId): string
    {
        return ProductResource::getUrl('view', ['record' => $productId]);
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return list<array{internal_option_key: string, external_option_value: string}>
     */
    private function canonicalizeOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $internal = $option['internal_option_key'] ?? null;
            $external = $option['external_option_value'] ?? null;

            if (! is_string($internal) || ! is_string($external)) {
                continue;
            }

            $normalized[] = [
                'internal_option_key' => $internal,
                'external_option_value' => $external,
            ];
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => [$left['internal_option_key'], $left['external_option_value']]
                <=> [$right['internal_option_key'], $right['external_option_value']],
        );

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    private function optionMappingForKey(array $options, string $internalOptionKey): ?string
    {
        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            if (($option['internal_option_key'] ?? null) === $internalOptionKey) {
                $external = $option['external_option_value'] ?? null;

                return is_string($external) ? $external : null;
            }
        }

        return null;
    }
}
