<?php

namespace App\Enums;

enum ConnectorDiscoveryRunLifecycleErrorCode: string
{
    case DispatchFailed = 'discovery_dispatch_failed';
    case JobFailed = 'discovery_job_failed';
    case AttemptsExhaustedWithoutResult = 'discovery_attempts_exhausted_without_result';
    case AccountDisabledBeforeExecution = 'discovery_account_disabled_before_execution';
    case SourceInvalidBeforeExecution = 'discovery_source_invalid_before_execution';

    public function cause(): ConnectorErrorCause
    {
        return match ($this) {
            self::AccountDisabledBeforeExecution,
            self::SourceInvalidBeforeExecution => ConnectorErrorCause::Configuration,
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
            self::AttemptsExhaustedWithoutResult,
            self::SourceInvalidBeforeExecution => ConnectorErrorActionability::SupportRequired,
        };
    }

    public function messageKey(): string
    {
        return match ($this) {
            self::AccountDisabledBeforeExecution => 'connectors.errors.account_disabled',
            self::DispatchFailed,
            self::JobFailed,
            self::AttemptsExhaustedWithoutResult,
            self::SourceInvalidBeforeExecution => 'connectors.errors.discovery_failed',
        };
    }

    public function technicalSummary(): string
    {
        return match ($this) {
            self::DispatchFailed => 'queue_dispatch_failed',
            self::JobFailed => 'queue_job_failed',
            self::AttemptsExhaustedWithoutResult => 'vendor_attempt_budget_exhausted_without_result',
            self::AccountDisabledBeforeExecution => 'account_disabled_before_execution',
            self::SourceInvalidBeforeExecution => 'source_invalid_before_execution',
        };
    }
}
