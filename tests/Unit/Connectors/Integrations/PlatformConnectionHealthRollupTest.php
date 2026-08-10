<?php

namespace Tests\Unit\Connectors\Integrations;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Models\ConnectorAccount;
use App\Support\Connectors\Integrations\PlatformConnectionHealthRollup;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformConnectionHealthRollupTest extends TestCase
{
    #[Test]
    public function zero_accounts_is_not_connected_without_inventing_enum_case(): void
    {
        $health = app(PlatformConnectionHealthRollup::class)->rollup([]);

        $this->assertTrue($health->isNotConnected());
        $this->assertNull($health->connectionStatus);
        $this->assertSame(0, $health->accountCount);
    }

    #[Test]
    public function mixed_healthy_and_disabled_does_not_rollup_to_disabled(): void
    {
        $accounts = [
            $this->account(true, ConnectorAccountConnectionStatus::Connected),
            $this->account(true, ConnectorAccountConnectionStatus::Connected),
            $this->account(false, ConnectorAccountConnectionStatus::Disabled),
        ];

        $health = app(PlatformConnectionHealthRollup::class)->rollup($accounts);

        $this->assertSame(ConnectorAccountConnectionStatus::Connected, $health->connectionStatus);
        $this->assertSame(2, $health->enabledCount);
        $this->assertSame(1, $health->disabledCount);
        $this->assertNotSame(ConnectorAccountConnectionStatus::Disabled, $health->connectionStatus);
    }

    #[Test]
    public function all_disabled_accounts_rollup_to_disabled(): void
    {
        $accounts = [
            $this->account(false, ConnectorAccountConnectionStatus::Connected),
            $this->account(false, ConnectorAccountConnectionStatus::Untested),
        ];

        $health = app(PlatformConnectionHealthRollup::class)->rollup($accounts);

        $this->assertSame(ConnectorAccountConnectionStatus::Disabled, $health->connectionStatus);
        $this->assertSame(0, $health->enabledCount);
        $this->assertSame(2, $health->disabledCount);
    }

    #[Test]
    public function attention_required_wins_over_temporary_and_untested(): void
    {
        $accounts = [
            $this->account(true, ConnectorAccountConnectionStatus::Connected),
            $this->account(true, ConnectorAccountConnectionStatus::TemporarilyUnavailable),
            $this->account(true, ConnectorAccountConnectionStatus::AttentionRequired),
            $this->account(true, ConnectorAccountConnectionStatus::Untested),
        ];

        $health = app(PlatformConnectionHealthRollup::class)->rollup($accounts);

        $this->assertSame(ConnectorAccountConnectionStatus::AttentionRequired, $health->connectionStatus);
        $this->assertSame(1, $health->attentionRequiredCount);
    }

    #[Test]
    public function temporary_unavailable_wins_over_untested_when_no_attention(): void
    {
        $accounts = [
            $this->account(true, ConnectorAccountConnectionStatus::Connected),
            $this->account(true, ConnectorAccountConnectionStatus::TemporarilyUnavailable),
            $this->account(true, ConnectorAccountConnectionStatus::Untested),
        ];

        $health = app(PlatformConnectionHealthRollup::class)->rollup($accounts);

        $this->assertSame(ConnectorAccountConnectionStatus::TemporarilyUnavailable, $health->connectionStatus);
    }

    #[Test]
    public function untested_wins_when_no_worse_enabled_status(): void
    {
        $accounts = [
            $this->account(true, ConnectorAccountConnectionStatus::Connected),
            $this->account(true, ConnectorAccountConnectionStatus::Untested),
            $this->account(false, ConnectorAccountConnectionStatus::AttentionRequired),
        ];

        $health = app(PlatformConnectionHealthRollup::class)->rollup($accounts);

        $this->assertSame(ConnectorAccountConnectionStatus::Untested, $health->connectionStatus);
    }

    private function account(bool $enabled, ConnectorAccountConnectionStatus $status): ConnectorAccount
    {
        $account = new ConnectorAccount;
        $account->is_enabled = $enabled;
        $account->connection_status = $status;

        return $account;
    }
}
