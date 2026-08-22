<?php

namespace App\Support\Connectors\AdobePaaS\EntityTrust;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteGetClassification;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteGetClassifier;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateClient;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateNormalizer;

/**
 * Read-only bounded stock Magento Product GET for pre-trust candidate discovery.
 * Exposes no mutation methods.
 */
final class AdobeProductCandidateDiscoveryClient
{
    public function __construct(
        private readonly AdobeProductRemoteStateClient $remoteStateClient,
        private readonly AdobeProductRemoteGetClassifier $getClassifier,
        private readonly AdobeProductRemoteStateNormalizer $normalizer,
    ) {}

    public function discoverSimpleOrChild(
        AdobePaaSRequestContext $context,
        string $sku,
    ): ?AdobeProductCandidateDiscoveryResult {
        [$httpResult, $transportException] = $this->remoteStateClient->sendReadOnlyGetWithContext($context, $sku);

        $classified = $this->getClassifier->classify($sku, $httpResult, $transportException);

        if ($classified->classification !== AdobeProductRemoteGetClassification::Found
            || $classified->observedState === null
            || $httpResult === null
        ) {
            return null;
        }

        $logicalEntityId = $this->extractLogicalEntityId($httpResult->body);

        if ($logicalEntityId === null) {
            return null;
        }

        return new AdobeProductCandidateDiscoveryResult(
            logicalEntityId: $logicalEntityId,
            sku: $classified->observedState->sku,
            typeId: $classified->observedState->typeId,
            simpleObservedState: $classified->observedState,
        );
    }

    public function discoverConfigurableParent(
        AdobePaaSRequestContext $context,
        string $parentSku,
    ): ?AdobeProductCandidateDiscoveryResult {
        [$httpResult, $transportException] = $this->remoteStateClient->sendReadOnlyGetWithContext($context, $parentSku);

        $classified = $this->getClassifier->classifyParent($parentSku, $httpResult, $transportException);

        if ($classified->classification !== AdobeProductRemoteGetClassification::Found
            || $classified->observedState === null
            || $httpResult === null
        ) {
            return null;
        }

        $logicalEntityId = $this->extractLogicalEntityId($httpResult->body);

        if ($logicalEntityId === null) {
            return null;
        }

        return new AdobeProductCandidateDiscoveryResult(
            logicalEntityId: $logicalEntityId,
            sku: $classified->observedState->sku,
            typeId: $classified->observedState->typeId,
            parentObservedState: $classified->observedState,
        );
    }

    /**
     * @return list<string>
     */
    public function discoverExtraRemoteChildSkus(
        AdobePaaSRequestContext $context,
        string $parentSku,
        array $expectedChildSkus,
    ): array {
        [$childrenGetResult] = $this->remoteStateClient->getConfigurableChildren($context, $parentSku);

        if ($childrenGetResult === null || $childrenGetResult->statusCode !== 200) {
            return [];
        }

        $payload = json_decode($childrenGetResult->body, true);

        if (! is_array($payload)) {
            return [];
        }

        $remoteSkus = [];
        $expectedLookup = array_fill_keys($expectedChildSkus, true);

        foreach ($payload as $child) {
            if (! is_array($child)) {
                continue;
            }

            $sku = $child['sku'] ?? null;

            if (! is_string($sku) || $sku === '') {
                continue;
            }

            if (! isset($expectedLookup[$sku])) {
                $remoteSkus[] = $sku;
            }
        }

        sort($remoteSkus);

        return $remoteSkus;
    }

    private function extractLogicalEntityId(string $body): ?int
    {
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return null;
        }

        $id = $payload['id'] ?? null;

        if (is_int($id)) {
            return $id;
        }

        if (is_string($id) && ctype_digit($id)) {
            return (int) $id;
        }

        return null;
    }
}
