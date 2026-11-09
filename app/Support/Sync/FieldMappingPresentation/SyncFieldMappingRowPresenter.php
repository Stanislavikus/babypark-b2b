<?php

namespace App\Support\Sync\FieldMappingPresentation;

use App\Support\Sync\FieldMappingReadModel\FieldMappingInternalRow;

final class SyncFieldMappingRowState
{
    public const MAPPED = 'mapped';

    public const SUGGESTED = 'suggested';

    public const UNMAPPED = 'unmapped';

    public const NEEDS_ATTENTION = 'needs_attention';
}

final class SyncFieldMappingRowPresenter
{
    public function semanticState(FieldMappingInternalRow $row, bool $discoveryAvailable): string
    {
        if ($row->needsAttention) {
            return SyncFieldMappingRowState::NEEDS_ATTENTION;
        }

        if ($row->existingExternalFieldKey !== null) {
            return SyncFieldMappingRowState::MAPPED;
        }

        if ($discoveryAvailable && $row->suggestedExternalFieldKey !== null) {
            return SyncFieldMappingRowState::SUGGESTED;
        }

        return SyncFieldMappingRowState::UNMAPPED;
    }

    public function effectiveExternalLabel(
        FieldMappingInternalRow $row,
        bool $discoveryAvailable,
        array $externalLabelsByKey,
    ): ?string {
        $key = $row->existingExternalFieldKey
            ?? ($discoveryAvailable ? ($row->suggestedExternalFieldKey ?? null) : null);

        if ($key === null) {
            return null;
        }

        return $externalLabelsByKey[$key] ?? $key;
    }
}
