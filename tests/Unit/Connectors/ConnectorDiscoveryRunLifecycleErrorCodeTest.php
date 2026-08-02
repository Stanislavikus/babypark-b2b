<?php

namespace Tests\Unit\Connectors;

use App\Enums\ConnectorDiscoveryRunLifecycleErrorCode;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorDiscoveryRunLifecycleErrorCodeTest extends TestCase
{
    #[Test]
    public function dispatch_failed_has_expected_mappings(): void
    {
        $case = ConnectorDiscoveryRunLifecycleErrorCode::DispatchFailed;

        $this->assertSame('discovery_dispatch_failed', $case->value);
        $this->assertSame(ConnectorErrorCause::Unknown, $case->cause());
        $this->assertSame(ConnectorErrorActionability::SupportRequired, $case->actionability());
        $this->assertSame('connectors.errors.discovery_failed', $case->messageKey());
        $this->assertSame('queue_dispatch_failed', $case->technicalSummary());
    }

    #[Test]
    public function job_failed_has_expected_mappings(): void
    {
        $case = ConnectorDiscoveryRunLifecycleErrorCode::JobFailed;

        $this->assertSame('discovery_job_failed', $case->value);
        $this->assertSame(ConnectorErrorCause::Unknown, $case->cause());
        $this->assertSame(ConnectorErrorActionability::SupportRequired, $case->actionability());
        $this->assertSame('connectors.errors.discovery_failed', $case->messageKey());
        $this->assertSame('queue_job_failed', $case->technicalSummary());
    }

    #[Test]
    public function attempts_exhausted_without_result_has_expected_mappings(): void
    {
        $case = ConnectorDiscoveryRunLifecycleErrorCode::AttemptsExhaustedWithoutResult;

        $this->assertSame('discovery_attempts_exhausted_without_result', $case->value);
        $this->assertSame(ConnectorErrorCause::Unknown, $case->cause());
        $this->assertSame(ConnectorErrorActionability::SupportRequired, $case->actionability());
        $this->assertSame('connectors.errors.discovery_failed', $case->messageKey());
        $this->assertSame('vendor_attempt_budget_exhausted_without_result', $case->technicalSummary());
    }

    #[Test]
    public function account_disabled_before_execution_has_expected_mappings(): void
    {
        $case = ConnectorDiscoveryRunLifecycleErrorCode::AccountDisabledBeforeExecution;

        $this->assertSame('discovery_account_disabled_before_execution', $case->value);
        $this->assertSame(ConnectorErrorCause::Configuration, $case->cause());
        $this->assertSame(ConnectorErrorActionability::WorkspaceAdminRequired, $case->actionability());
        $this->assertSame('connectors.errors.account_disabled', $case->messageKey());
        $this->assertSame('account_disabled_before_execution', $case->technicalSummary());
    }

    #[Test]
    public function source_invalid_before_execution_has_expected_mappings(): void
    {
        $case = ConnectorDiscoveryRunLifecycleErrorCode::SourceInvalidBeforeExecution;

        $this->assertSame('discovery_source_invalid_before_execution', $case->value);
        $this->assertSame(ConnectorErrorCause::Configuration, $case->cause());
        $this->assertSame(ConnectorErrorActionability::SupportRequired, $case->actionability());
        $this->assertSame('connectors.errors.discovery_failed', $case->messageKey());
        $this->assertSame('source_invalid_before_execution', $case->technicalSummary());
    }
}
