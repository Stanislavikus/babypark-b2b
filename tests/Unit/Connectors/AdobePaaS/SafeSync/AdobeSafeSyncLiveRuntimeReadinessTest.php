<?php

namespace Tests\Unit\Connectors\AdobePaaS\SafeSync;

use App\Models\ConnectorAccount;
use App\Services\Connectors\AdobeSafeSyncComponentReadinessResolver;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncLiveRuntimeReadiness;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeSafeSyncLiveRuntimeReadinessTest extends TestCase
{
    #[Test]
    public function unsupported_auth_profile_fails_closed_without_running_adobe_readiness(): void
    {
        $account = new ConnectorAccount;
        $account->setAttribute('auth_profile', 'unsupported_profile');

        $readiness = new AdobeSafeSyncLiveRuntimeReadiness(
            app(AdobeSafeSyncComponentReadinessResolver::class),
        );

        $this->assertFalse($readiness->isReady($account));
    }
}
