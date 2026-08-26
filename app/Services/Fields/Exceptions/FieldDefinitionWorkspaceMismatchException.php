<?php

namespace App\Services\Fields\Exceptions;

final class FieldDefinitionWorkspaceMismatchException extends FieldValueWriterException
{
    public static function forId(string $fieldDefinitionId, string $bindingWorkspaceId, ?string $definitionWorkspaceId): self
    {
        $definitionLabel = $definitionWorkspaceId ?? '<global>';

        return new self(
            "Field definition {$fieldDefinitionId} belongs to workspace {$definitionLabel}, but its binding belongs to {$bindingWorkspaceId}. Definition and binding must share the same workspace (including global)."
        );
    }
}
