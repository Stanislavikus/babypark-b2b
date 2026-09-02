<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

use App\Enums\ConnectorComponentReadiness;
use App\Models\ConnectorAccount;
use App\Services\Connectors\AdobeSafeSyncComponentReadinessResolver;
use App\Support\Sync\Live\ConnectorLiveRuntimeReadiness;

final class AdobeSafeSyncLiveRuntimeReadiness implements ConnectorLiveRuntimeReadiness
{
    private const ADOBE_AUTH_PROFILE = 'adobe_commerce_paas_oauth1_integration';

    public function __construct(
        private readonly AdobeSafeSyncComponentReadinessResolver $readinessResolver,
    ) {}

    public function isReady(ConnectorAccount $account): bool
    {
        if ($account->auth_profile !== self::ADOBE_AUTH_PROFILE) {
            return true;
        }

        $result = $this->readinessResolver->resolve(
            $account->workspace_id,
            (string) $account->getKey(),
            AdobeSafeSyncRequiredOperation::SimpleProductWrite,
        );

        return $result->baselineSucceeded
            && $result->componentReadiness === ConnectorComponentReadiness::Ready;
    }
}
