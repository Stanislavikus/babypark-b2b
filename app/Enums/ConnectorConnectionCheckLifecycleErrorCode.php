<?php

namespace App\Enums;

enum ConnectorConnectionCheckLifecycleErrorCode: string
{
    case DispatchFailed = 'connection_check_dispatch_failed';
    case JobFailed = 'connection_check_job_failed';
    case AttemptsExhaustedWithoutResult = 'connection_check_attempts_exhausted_without_result';
    case AccountDisabledBeforeExecution = 'connection_check_account_disabled_before_execution';

    public function cause(): ConnectorErrorCause
    {
        return match ($this) {
            self::AccountDisabledBeforeExecution => ConnectorErrorCause::Configuration,
            self::DispatchFailed,
            self::JobFailed,
            self::AttemptsExhaustedWithoutResult => ConnectorErrorCause::Unknown,
        };
    }

    public function actionability(): ConnectorErrorActionability
    {
        return match ($this) {
            self::AccountDisabledBeforeExecution => ConnectorErrorActionability::WorkspaceAdminRequired,
            self::DispatchFailed,
            self::JobFailed,
            self::AttemptsExhaustedWithoutResult => ConnectorErrorActionability::SupportRequired,
        };
    }

    public function messageKey(): string
    {
        return match ($this) {
            self::AccountDisabledBeforeExecution => 'connectors.errors.account_disabled',
            self::DispatchFailed,
            self::JobFailed,
            self::AttemptsExhaustedWithoutResult => 'connectors.errors.connection_check_failed',
        };
    }

    public function technicalSummary(): string
    {
        return match ($this) {
            self::DispatchFailed => 'queue_dispatch_failed',
            self::JobFailed => 'queue_job_failed',
            self::AttemptsExhaustedWithoutResult => 'vendor_attempt_budget_exhausted_without_result',
            self::AccountDisabledBeforeExecution => 'account_disabled_before_execution',
        };
    }
}
