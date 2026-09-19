<?php

namespace App\Support\Connectors;

final class ConnectorSchemaFieldPresenter
{
    /**
     * @var list<string>
     */
    public const NORMALIZED_DATA_TYPES = [
        'text',
        'long_text',
        'date',
        'datetime',
        'boolean',
        'select',
        'multi_select',
        'money',
        'image',
        'image_collection',
        'number',
    ];

    /**
     * @var list<string>
     */
    public const EXTERNAL_SCOPES = [
        'global',
        'website',
        'store',
    ];

    /**
     * @return array<string, string>
     */
    public static function normalizedDataTypeOptions(): array
    {
        $options = [];

        foreach (self::NORMALIZED_DATA_TYPES as $type) {
            $options[$type] = self::normalizedDataTypeLabel($type);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function externalScopeOptions(): array
    {
        $options = [
            '__unknown__' => self::externalScopeLabel(null),
        ];

        foreach (self::EXTERNAL_SCOPES as $scope) {
            $options[$scope] = self::externalScopeLabel($scope);
        }

        return $options;
    }

    public static function normalizedDataTypeLabel(?string $type): string
    {
        if ($type === null || $type === '') {
            return __('connectors.ui.snapshot.fields.type.unknown');
        }

        $key = "connectors.ui.snapshot.fields.type.{$type}";

        return __($key) === $key
            ? __('connectors.ui.snapshot.fields.type.unknown')
            : __($key);
    }

    public static function externalScopeLabel(?string $scope): string
    {
        if ($scope === null || $scope === '') {
            return __('connectors.ui.snapshot.fields.scope.unknown');
        }

        $key = "connectors.ui.snapshot.fields.scope.{$scope}";

        return __($key) === $key
            ? __('connectors.ui.snapshot.fields.scope.unknown')
            : __($key);
    }

    public static function booleanLabel(?bool $value): string
    {
        return match ($value) {
            true => __('connectors.ui.snapshot.fields.boolean.yes'),
            false => __('connectors.ui.snapshot.fields.boolean.no'),
            default => __('connectors.ui.snapshot.fields.boolean.unknown'),
        };
    }

    public static function sortOrderLabel(?int $sortOrder): ?string
    {
        if ($sortOrder === null) {
            return null;
        }

        return (string) $sortOrder;
    }
}
