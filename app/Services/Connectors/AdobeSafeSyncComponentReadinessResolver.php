<?php

namespace App\Services\Connectors;

use App\Enums\ConnectorComponentReadiness;
use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Support\Connectors\AdobePaaS\AdobeMagentoVersionProbeCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncContract;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncHandshakeProbeCapability;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncReadinessResult;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncRequiredOperation;

final class AdobeSafeSyncComponentReadinessResolver
{
    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobePaaSConnectionCheckCapability $connectionCheck,
        private readonly AdobeMagentoVersionProbeCapability $magentoVersionProbe,
        private readonly AdobeSafeSyncHandshakeProbeCapability $handshakeProbe,
    ) {}

    public function resolve(string $workspaceId, string $connectorAccountId, AdobeSafeSyncRequiredOperation $operation): AdobeSafeSyncReadinessResult
    {
        $context = $this->contextFactory->create($workspaceId, $connectorAccountId);
        $baseline = $this->connectionCheck->checkConnection($context);

        if (! $baseline->succeeded) {
            return new AdobeSafeSyncReadinessResult($baseline, null, false);
        }

        $stockMagentoVersionEvidence = $this->magentoVersionProbe->probe($context);
        $probe = $this->handshakeProbe->probe($context);

        if (! $probe->connectionResult->succeeded) {
            $setupRequired = $probe->connectionResult->errorCode === ConnectorConnectionCheckErrorCode::AdobeInvalidOrUnsupportedEndpoint
                && $probe->connectionResult->httpStatus === 404;

            return new AdobeSafeSyncReadinessResult(
                $probe->connectionResult,
                $setupRequired ? ConnectorComponentReadiness::SetupRequired : null,
                true,
                stockMagentoVersionEvidence: $stockMagentoVersionEvidence,
            );
        }

        $handshake = $probe->handshake;
        $compatible = $handshake !== null
            && $handshake->contractVersion === AdobeSafeSyncContract::CONTRACT_VERSION
            && array_diff($operation->requiredFamilies(), $handshake->supportedOperationFamilies) === []
            && $this->moduleVersionSupports($operation, $handshake->moduleVersion);

        return new AdobeSafeSyncReadinessResult(
            $probe->connectionResult,
            $compatible ? ConnectorComponentReadiness::Ready : ConnectorComponentReadiness::UpdateRequired,
            true,
            $handshake?->moduleVersion,
            $handshake?->applicationVersion,
            $handshake?->phpVersion,
            $stockMagentoVersionEvidence,
        );
    }

    private function moduleVersionSupports(AdobeSafeSyncRequiredOperation $operation, string $moduleVersion): bool
    {
        if ($operation === AdobeSafeSyncRequiredOperation::ProductRead) {
            return true;
        }

        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $moduleVersion) === 1
            && version_compare($moduleVersion, AdobeSafeSyncContract::SIMPLE_PRODUCT_WRITE_MINIMUM_MODULE_VERSION, '>=');
    }
}
