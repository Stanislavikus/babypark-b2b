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
        $historical = $this->historicalMappingsByBindingId[$bindingId] ?? null;
        $current = $this->currentMappingsByBindingId[$bindingId] ?? null;

        if ($historical === null && $current === null) {
            return false;
        }

        if ($historical === null || $current === null) {
            return true;
        }

        return $historical['external_field_key'] !== $current['external_field_key']
            || $this->canonicalizeOptions($historical['option_mappings'])
            !== $this->canonicalizeOptions($current['option_mappings']);
    }

    public function optionMappingChanged(string $bindingId): bool
    {
        return $this->mappingChanged($bindingId);
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
            if ($this->mappingChanged($bindingId)) {
                return true;
            }
        }

        return false;
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
}
