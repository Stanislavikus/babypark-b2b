<?php

namespace App\Services\Sync;

use App\Enums\AttributeDataType;
use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\FieldMapping;

final class FieldOptionMappingEligibilityResolver
{
    public function __construct(
        private readonly FieldDefinitionOptionCatalog $optionCatalog,
    ) {}

    public function isEligibleMapping(FieldMapping $mapping): bool
    {
        $mapping->loadMissing('fieldBinding.fieldDefinition');

        $binding = $mapping->fieldBinding;
        $definition = $binding?->fieldDefinition;

        if ($binding === null || $definition === null) {
            return false;
        }

        return $this->isEligibleBindingAndDefinition($binding, $definition);
    }

    public function isEligibleBinding(FieldBinding $binding): bool
    {
        $binding->loadMissing('fieldDefinition');

        $definition = $binding->fieldDefinition;

        if ($definition === null) {
            return false;
        }

        return $this->isEligibleBindingAndDefinition($binding, $definition);
    }

    private function isEligibleBindingAndDefinition(FieldBinding $binding, FieldDefinition $definition): bool
    {
        if ($binding->status !== AttributeStatus::Active) {
            return false;
        }

        if ($definition->status !== AttributeStatus::Active) {
            return false;
        }

        if (! in_array($binding->object_type, [FieldObjectType::Product, FieldObjectType::ProductVariant], true)) {
            return false;
        }

        if ($definition->data_type !== AttributeDataType::Select) {
            return false;
        }

        if ($definition->is_multi_value) {
            return false;
        }

        return $this->optionCatalog->currentOptionCodes($definition) !== [];
    }
}
