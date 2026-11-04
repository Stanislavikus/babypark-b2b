<?php

namespace Tests\Unit\Connectors;

use App\Enums\ConnectorConnectionCheckLifecycleErrorCode;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorConnectionCheckLifecycleErrorCodeTest extends TestCase
{
    #[Test]
    public function lifecycle_codes_have_expected_mappings(): void
    {
        $this->assertSame(ConnectorErrorCause::Unknown, ConnectorConnectionCheckLifecycleErrorCode::DispatchFailed->cause());
        $this->assertSame(ConnectorErrorActionability::SupportRequired, ConnectorConnectionCheckLifecycleErrorCode::JobFailed->actionability());
        $this->assertSame('connectors.errors.connection_check_failed', ConnectorConnectionCheckLifecycleErrorCode::AttemptsExhaustedWithoutResult->messageKey());
        $this->assertSame('vendor_attempt_budget_exhausted_without_result', ConnectorConnectionCheckLifecycleErrorCode::AttemptsExhaustedWithoutResult->technicalSummary());

        $this->assertSame(ConnectorErrorCause::Configuration, ConnectorConnectionCheckLifecycleErrorCode::AccountDisabledBeforeExecution->cause());
        $this->assertSame(ConnectorErrorActionability::WorkspaceAdminRequired, ConnectorConnectionCheckLifecycleErrorCode::AccountDisabledBeforeExecution->actionability());
        $this->assertSame('connectors.errors.account_disabled', ConnectorConnectionCheckLifecycleErrorCode::AccountDisabledBeforeExecution->messageKey());
        $this->assertSame('account_disabled_before_execution', ConnectorConnectionCheckLifecycleErrorCode::AccountDisabledBeforeExecution->technicalSummary());
    }
}
