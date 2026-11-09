<?php

namespace App\Support\Sync\FieldMappingPresentation;

use App\Support\Sync\Exceptions\FieldMappingConflictException;
use App\Support\Sync\Exceptions\FieldMappingValidationException;
use Throwable;

final class FieldMappingSafeMessagePresenter
{
    public function present(Throwable $exception): string
    {
        if ($exception instanceof FieldMappingValidationException && $this->isStaleMappingState($exception)) {
            return __('sync_mappings.errors.stale_state');
        }

        if ($exception instanceof FieldMappingConflictException) {
            return __('sync_mappings.errors.conflict');
        }

        if ($exception instanceof FieldMappingValidationException) {
            return __('sync_mappings.errors.invalid_action');
        }

        return __('sync_mappings.errors.invalid_action');
    }

    public function report(Throwable $exception): void
    {
        report($exception);
    }

    private function isStaleMappingState(FieldMappingValidationException $exception): bool
    {
        return str_starts_with($exception->getMessage(), 'No field mapping identified by');
    }
}
