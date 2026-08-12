<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class FieldMappingValidationException extends RuntimeException
{
    public static function nonProductsConfiguration(string $syncConfigurationId): self
    {
        return new self(
            "Sync configuration {$syncConfigurationId} is not a products configuration.",
        );
    }

    public static function foreignWorkspaceBinding(string $fieldBindingId): self
    {
        return new self(
            "Field binding {$fieldBindingId} is not eligible for this workspace.",
        );
    }

    public static function foreignWorkspaceDefinition(string $fieldDefinitionId): self
    {
        return new self(
            "Field definition {$fieldDefinitionId} is not eligible for this workspace.",
        );
    }

    public static function archivedBinding(string $fieldBindingId): self
    {
        return new self(
            "Field binding {$fieldBindingId} is archived.",
        );
    }

    public static function archivedDefinition(string $fieldDefinitionId): self
    {
        return new self(
            "Field definition {$fieldDefinitionId} is archived.",
        );
    }

    public static function customerObjectType(string $fieldBindingId): self
    {
        return new self(
            "Field binding {$fieldBindingId} is not a product or product variant target.",
        );
    }

    public static function mappingNotFound(string $syncConfigurationId, string $identifier): self
    {
        return new self(
            "No field mapping identified by {$identifier} exists in sync configuration {$syncConfigurationId}.",
        );
    }
}
