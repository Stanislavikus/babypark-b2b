<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorSchemaFieldDisposition;

final class ConnectorSchemaFieldReadinessPresenter
{
    public static function dispositionLabel(?string $disposition): string
    {
        if ($disposition === null || ConnectorSchemaFieldDisposition::tryFrom($disposition) === null) {
            return __('connectors.ui.snapshot.fields.disposition.unclassified');
        }

        return __("connectors.ui.snapshot.fields.disposition.{$disposition}");
    }

    public static function readinessCode(
        ?string $disposition,
        ?string $mappingStrategy,
        bool $hasMapping,
        bool $hasConfigurationContext,
    ): string {
        $resolvedDisposition = $disposition === null
            ? null
            : ConnectorSchemaFieldDisposition::tryFrom($disposition);

        if ($resolvedDisposition === null) {
            return 'classification_pending';
        }

        return match ($resolvedDisposition) {
            ConnectorSchemaFieldDisposition::ReviewNeeded => 'review_required',
            ConnectorSchemaFieldDisposition::Unsupported => 'unsupported',
            ConnectorSchemaFieldDisposition::WorkspaceCustom => 'custom_deferred',
            ConnectorSchemaFieldDisposition::SystemOrDedicatedOwner => 'dedicated_owner',
            ConnectorSchemaFieldDisposition::ProviderStandard => $hasMapping
                ? 'mapped'
                : 'provider_recognized',
            ConnectorSchemaFieldDisposition::CanonicalPlatform => self::canonicalReadiness(
                $mappingStrategy,
                $hasMapping,
                $hasConfigurationContext,
            ),
        };
    }

    public static function readinessLabel(string $code): string
    {
        $key = "connectors.ui.snapshot.fields.readiness.{$code}";

        return __($key) === $key
            ? __('connectors.ui.snapshot.fields.readiness.classification_pending')
            : __($key);
    }

    private static function canonicalReadiness(
        ?string $mappingStrategy,
        bool $hasMapping,
        bool $hasConfigurationContext,
    ): string {
        if ($hasMapping) {
            return 'mapped';
        }

        if ($mappingStrategy === 'channel_deferred') {
            return 'canonical_deferred';
        }

        if ($mappingStrategy === 'verified_channel_mapping_rule') {
            return $hasConfigurationContext
                ? 'mapping_pending'
                : 'canonical_recognized';
        }

        return 'canonical_recognized';
    }
}
