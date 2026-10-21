<?php

namespace App\Support\Sync\Preview\Presentation;

use App\Models\FieldBinding;
use App\Models\ProductVariant;

final class SyncPreviewFieldContextPresenter
{
    private const KNOWN_FIELD_GROUPS = [
        'characteristics' => 'sync_preview.field_groups.characteristics',
        'dimensions' => 'sync_preview.field_groups.dimensions',
        'identifiers' => 'sync_preview.field_groups.identifiers',
    ];

    public function present(?FieldBinding $binding): ?string
    {
        if ($binding === null || $binding->fieldDefinition === null) {
            return null;
        }

        $objectLabel = $binding->object_type->label();
        $fieldLabel = $binding->fieldDefinition->localizedLabel();
        $groupCode = $binding->field_group;
        $groupKey = self::KNOWN_FIELD_GROUPS[$groupCode] ?? null;

        if ($groupKey !== null) {
            return $objectLabel.' → '.__($groupKey).' → '.$fieldLabel;
        }

        return $objectLabel.' → '.$fieldLabel;
    }

    public function presentVariantContext(?ProductVariant $variant, bool $missingSkuFinding): ?string
    {
        if ($variant === null) {
            return __('sync_preview.variant_context.generic');
        }

        $sku = is_string($variant->sku) ? trim($variant->sku) : '';

        if ($missingSkuFinding || $sku === '') {
            return __('sync_preview.variant_context.without_sku');
        }

        return __('sync_preview.variant_context.with_sku', ['sku' => $sku]);
    }
}
