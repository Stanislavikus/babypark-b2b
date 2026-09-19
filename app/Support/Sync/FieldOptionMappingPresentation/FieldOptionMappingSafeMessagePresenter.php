<?php

namespace App\Support\Sync\FieldOptionMappingPresentation;

use App\Support\Sync\Exceptions\FieldMappingValidationException;
use App\Support\Sync\Exceptions\FieldOptionMappingConflictException;
use App\Support\Sync\Exceptions\FieldOptionMappingStaleMutationException;
use Throwable;

final class FieldOptionMappingSafeMessagePresenter
{
    public function present(Throwable $exception): string
    {
        if ($exception instanceof FieldOptionMappingStaleMutationException) {
            return __('sync_option_mappings.errors.stale_state');
        }

        if ($exception instanceof FieldOptionMappingConflictException) {
            return __('sync_option_mappings.errors.conflict');
        }

        if ($exception instanceof FieldMappingValidationException) {
            return __('sync_option_mappings.errors.invalid_action');
        }

        return __('sync_option_mappings.errors.verification_failed');
    }

    public function report(Throwable $exception): void
    {
        report($exception);
    }
}
