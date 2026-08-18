<?php

namespace App\Support\Sync\Preview\Presentation;

use App\Enums\SyncPreviewFindingCode;
use App\Enums\SyncPreviewRemediationActionability;
use App\Enums\SyncPreviewRemediationArea;
use App\Models\FieldBinding;

final class SyncPreviewFindingPresenter
{
    private const REQUIRED_EXTERNAL_CONCEPTS = [
        'name' => 'sync_preview.required_concepts.name',
        'sku' => 'sync_preview.required_concepts.sku',
        'status' => 'sync_preview.required_concepts.status',
    ];

    public function __construct(
        private readonly SyncPreviewFieldContextPresenter $fieldContextPresenter,
    ) {}

    /**
     * @param  array<string, mixed>  $finding
     */
    public function present(
        array $finding,
        SyncPreviewPresentationContext $context,
        string $productId,
    ): SyncPreviewFindingPresentation {
        $codeValue = $finding['code'] ?? null;
        $code = is_string($codeValue) ? SyncPreviewFindingCode::tryFrom($codeValue) : null;

        if ($code === null) {
            return new SyncPreviewFindingPresentation(
                summary: __('sync_preview.findings.generic'),
                fieldContext: null,
                variantContext: null,
                destinations: [],
            );
        }

        $subject = is_string($finding['subject'] ?? null) ? $finding['subject'] : null;
        $rawContext = $finding['context'] ?? [];
        $findingContext = is_array($rawContext) ? $rawContext : [];
        $bindingId = is_string($findingContext['field_binding_id'] ?? null)
            ? $findingContext['field_binding_id']
            : null;
        $binding = $context->binding($bindingId);
        $variant = $context->variant($subject);
        $fieldContext = $this->fieldContextPresenter->present($binding);
        $variantContext = $this->fieldContextPresenter->presentVariantContext(
            $variant,
            $code === SyncPreviewFindingCode::MissingSku,
        );

        return match ($code) {
            SyncPreviewFindingCode::MissingRequiredFieldMapping => $this->presentMissingRequiredFieldMapping(
                $subject,
                $context,
            ),
            SyncPreviewFindingCode::MissingMappedProductValue,
            SyncPreviewFindingCode::MissingName => $this->presentProductDataFinding($code, $binding, $fieldContext, $variantContext, $context, $productId),
            SyncPreviewFindingCode::MissingMappedVariantValue,
            SyncPreviewFindingCode::MissingSku,
            SyncPreviewFindingCode::DuplicateConfigurableCombination,
            SyncPreviewFindingCode::NoSellableVariant,
            SyncPreviewFindingCode::ConfigurableVariantsIncomplete => $this->presentVariantDataFinding($code, $binding, $fieldContext, $variantContext, $context, $productId),
            SyncPreviewFindingCode::MissingOptionMapping,
            SyncPreviewFindingCode::ExternalOptionMissingOrStale => $this->presentOptionMappingFinding($code, $binding, $fieldContext, $variantContext, $bindingId, $context, $productId),
            SyncPreviewFindingCode::AttributeSetUnconfigured,
            SyncPreviewFindingCode::AttributeSetInvalid => $this->presentConnectorSetupFinding($code, $fieldContext, $variantContext, $context),
            SyncPreviewFindingCode::MappedFieldAbsentFromSelectedSet => $this->presentMappedFieldAbsentFromSelectedSet($binding, $fieldContext, $variantContext, $bindingId, $context),
            SyncPreviewFindingCode::InvalidConfigurableAttribute,
            SyncPreviewFindingCode::NoConfigurableDimension => $this->presentFieldMappingFinding($code, $binding, $fieldContext, $variantContext, $bindingId, $context),
            SyncPreviewFindingCode::PriceUnavailable,
            SyncPreviewFindingCode::PriceConfigurationError => $this->presentPricingFinding($code, $fieldContext, $variantContext, $context, $productId),
        };
    }

    private function presentMissingRequiredFieldMapping(
        ?string $subject,
        SyncPreviewPresentationContext $context,
    ): SyncPreviewFindingPresentation {
        $conceptKey = $subject !== null ? (self::REQUIRED_EXTERNAL_CONCEPTS[$subject] ?? null) : null;
        $summary = $conceptKey !== null
            ? __('sync_preview.findings.missing_required_field_mapping_for', ['concept' => __($conceptKey)])
            : __('sync_preview.findings.missing_required_field_mapping');

        $historicalSubject = $subject;
        $currentHasMapping = false;

        if ($historicalSubject !== null) {
            foreach ($context->currentMappingsByBindingId as $mapping) {
                if ($mapping['external_field_key'] === $historicalSubject) {
                    $currentHasMapping = true;
                    break;
                }
            }
        }

        $actionability = $currentHasMapping
            ? SyncPreviewRemediationActionability::CurrentConfigurationChanged
            : $this->fieldMappingActionability($context, null);

        return new SyncPreviewFindingPresentation(
            summary: $summary,
            fieldContext: null,
            variantContext: null,
            destinations: [
                $this->fieldMappingDestination($context, $actionability),
            ],
        );
    }

    private function presentFieldMappingFinding(
        SyncPreviewFindingCode $code,
        ?FieldBinding $binding,
        ?string $fieldContext,
        ?string $variantContext,
        ?string $bindingId,
        SyncPreviewPresentationContext $context,
    ): SyncPreviewFindingPresentation {
        if ($code === SyncPreviewFindingCode::NoConfigurableDimension) {
            $actionability = $context->mappingSetChanged()
                ? SyncPreviewRemediationActionability::CurrentConfigurationChanged
                : $this->fieldMappingActionability($context, $bindingId);
        } else {
            $actionability = ($bindingId !== null && $context->mappingChanged($bindingId))
                ? SyncPreviewRemediationActionability::CurrentConfigurationChanged
                : $this->fieldMappingActionability($context, $bindingId);
        }

        return new SyncPreviewFindingPresentation(
            summary: __($code->messageKey()),
            fieldContext: $fieldContext,
            variantContext: $variantContext,
            destinations: [
                $this->fieldMappingDestination($context, $actionability),
            ],
        );
    }

    private function presentMappedFieldAbsentFromSelectedSet(
        ?FieldBinding $binding,
        ?string $fieldContext,
        ?string $variantContext,
        ?string $bindingId,
        SyncPreviewPresentationContext $context,
    ): SyncPreviewFindingPresentation {
        $mappingActionability = ($bindingId !== null && $context->mappingChanged($bindingId))
            ? SyncPreviewRemediationActionability::CurrentConfigurationChanged
            : $this->fieldMappingActionability($context, $bindingId);

        $setupActionability = $context->connectorSetupChanged()
            ? SyncPreviewRemediationActionability::CurrentConfigurationChanged
            : ($context->canManageSetup
                ? SyncPreviewRemediationActionability::ActionAvailable
                : SyncPreviewRemediationActionability::PermissionRequired);

        return new SyncPreviewFindingPresentation(
            summary: __(SyncPreviewFindingCode::MappedFieldAbsentFromSelectedSet->messageKey()),
            fieldContext: $fieldContext,
            variantContext: $variantContext,
            destinations: [
                $this->fieldMappingDestination($context, $mappingActionability),
                $this->connectorSetupDestination($context, $setupActionability),
            ],
        );
    }

    private function presentProductDataFinding(
        SyncPreviewFindingCode $code,
        ?FieldBinding $binding,
        ?string $fieldContext,
        ?string $variantContext,
        SyncPreviewPresentationContext $context,
        string $productId,
    ): SyncPreviewFindingPresentation {
        return new SyncPreviewFindingPresentation(
            summary: __($code->messageKey()),
            fieldContext: $fieldContext,
            variantContext: $variantContext,
            destinations: [
                $this->noEditSurfaceDestination(SyncPreviewRemediationArea::ProductData),
                $this->productContextDestination($context, $productId),
            ],
        );
    }

    private function presentVariantDataFinding(
        SyncPreviewFindingCode $code,
        ?FieldBinding $binding,
        ?string $fieldContext,
        ?string $variantContext,
        SyncPreviewPresentationContext $context,
        string $productId,
    ): SyncPreviewFindingPresentation {
        return new SyncPreviewFindingPresentation(
            summary: __($code->messageKey()),
            fieldContext: $fieldContext,
            variantContext: $variantContext,
            destinations: [
                $this->noEditSurfaceDestination(SyncPreviewRemediationArea::VariantData),
                $this->productContextDestination($context, $productId),
            ],
        );
    }

    private function presentOptionMappingFinding(
        SyncPreviewFindingCode $code,
        ?FieldBinding $binding,
        ?string $fieldContext,
        ?string $variantContext,
        ?string $bindingId,
        SyncPreviewPresentationContext $context,
        string $productId,
    ): SyncPreviewFindingPresentation {
        $actionability = ($bindingId !== null && $context->optionMappingChanged($bindingId))
            ? SyncPreviewRemediationActionability::CurrentConfigurationChanged
            : SyncPreviewRemediationActionability::NoEditSurface;

        return new SyncPreviewFindingPresentation(
            summary: __($code->messageKey()),
            fieldContext: $fieldContext,
            variantContext: $variantContext,
            destinations: [
                new SyncPreviewRemediationDestinationPresentation(
                    area: SyncPreviewRemediationArea::OptionMapping,
                    actionability: $actionability,
                    label: __('sync_preview.remediation.option_mapping'),
                    actionLabel: null,
                    actionUrl: null,
                    statusMessage: $this->statusMessageFor($actionability),
                ),
                $this->productContextDestination($context, $productId),
            ],
        );
    }

    private function presentConnectorSetupFinding(
        SyncPreviewFindingCode $code,
        ?string $fieldContext,
        ?string $variantContext,
        SyncPreviewPresentationContext $context,
    ): SyncPreviewFindingPresentation {
        $actionability = $context->connectorSetupChanged()
            ? SyncPreviewRemediationActionability::CurrentConfigurationChanged
            : ($context->canManageSetup
                ? SyncPreviewRemediationActionability::ActionAvailable
                : SyncPreviewRemediationActionability::PermissionRequired);

        return new SyncPreviewFindingPresentation(
            summary: __($code->messageKey()),
            fieldContext: $fieldContext,
            variantContext: $variantContext,
            destinations: [
                $this->connectorSetupDestination($context, $actionability),
            ],
        );
    }

    private function presentPricingFinding(
        SyncPreviewFindingCode $code,
        ?string $fieldContext,
        ?string $variantContext,
        SyncPreviewPresentationContext $context,
        string $productId,
    ): SyncPreviewFindingPresentation {
        return new SyncPreviewFindingPresentation(
            summary: __($code->messageKey()),
            fieldContext: $fieldContext,
            variantContext: $variantContext,
            destinations: [
                $this->noEditSurfaceDestination(SyncPreviewRemediationArea::Pricing),
                $this->productContextDestination($context, $productId),
            ],
        );
    }

    private function fieldMappingActionability(
        SyncPreviewPresentationContext $context,
        ?string $bindingId,
    ): SyncPreviewRemediationActionability {
        if ($bindingId !== null && $context->mappingChanged($bindingId)) {
            return SyncPreviewRemediationActionability::CurrentConfigurationChanged;
        }

        if ($context->canManageMappings) {
            return SyncPreviewRemediationActionability::ActionAvailable;
        }

        if ($context->canViewMappings) {
            return SyncPreviewRemediationActionability::ViewOnly;
        }

        return SyncPreviewRemediationActionability::PermissionRequired;
    }

    private function fieldMappingDestination(
        SyncPreviewPresentationContext $context,
        SyncPreviewRemediationActionability $actionability,
    ): SyncPreviewRemediationDestinationPresentation {
        return new SyncPreviewRemediationDestinationPresentation(
            area: SyncPreviewRemediationArea::FieldMapping,
            actionability: $actionability,
            label: __('sync_preview.remediation.field_mapping'),
            actionLabel: match ($actionability) {
                SyncPreviewRemediationActionability::ActionAvailable,
                SyncPreviewRemediationActionability::ViewOnly => __('sync_preview.actions.configure_mapping'),
                default => null,
            },
            actionUrl: in_array($actionability, [
                SyncPreviewRemediationActionability::ActionAvailable,
                SyncPreviewRemediationActionability::ViewOnly,
            ], true)
                ? $context->fieldMappingUrl()
                : null,
            statusMessage: $this->statusMessageFor($actionability),
        );
    }

    private function connectorSetupDestination(
        SyncPreviewPresentationContext $context,
        SyncPreviewRemediationActionability $actionability,
    ): SyncPreviewRemediationDestinationPresentation {
        return new SyncPreviewRemediationDestinationPresentation(
            area: SyncPreviewRemediationArea::ConnectorSetup,
            actionability: $actionability,
            label: __('sync_preview.remediation.connector_setup'),
            actionLabel: $actionability === SyncPreviewRemediationActionability::ActionAvailable
                ? __('sync_preview.actions.configure_adobe')
                : null,
            actionUrl: $actionability === SyncPreviewRemediationActionability::ActionAvailable
                ? $context->connectorSetupUrl()
                : null,
            statusMessage: $this->statusMessageFor($actionability),
        );
    }

    private function noEditSurfaceDestination(SyncPreviewRemediationArea $area): SyncPreviewRemediationDestinationPresentation
    {
        return new SyncPreviewRemediationDestinationPresentation(
            area: $area,
            actionability: SyncPreviewRemediationActionability::NoEditSurface,
            label: __('sync_preview.remediation.'.$area->value),
            actionLabel: null,
            actionUrl: null,
            statusMessage: __('sync_preview.status.no_edit_surface'),
        );
    }

    private function productContextDestination(
        SyncPreviewPresentationContext $context,
        string $productId,
    ): SyncPreviewRemediationDestinationPresentation {
        return new SyncPreviewRemediationDestinationPresentation(
            area: SyncPreviewRemediationArea::ProductData,
            actionability: SyncPreviewRemediationActionability::ViewOnly,
            label: __('sync_preview.remediation.product_context'),
            actionLabel: __('sync_preview.actions.open_product'),
            actionUrl: $context->productViewUrl($productId),
            statusMessage: null,
        );
    }

    private function statusMessageFor(SyncPreviewRemediationActionability $actionability): ?string
    {
        return match ($actionability) {
            SyncPreviewRemediationActionability::PermissionRequired => __('sync_preview.status.permission_required'),
            SyncPreviewRemediationActionability::NoEditSurface => __('sync_preview.status.no_edit_surface'),
            SyncPreviewRemediationActionability::CurrentConfigurationChanged => __('sync_preview.status.configuration_changed'),
            default => null,
        };
    }
}
