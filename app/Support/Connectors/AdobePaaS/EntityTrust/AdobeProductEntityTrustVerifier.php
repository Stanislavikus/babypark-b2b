<?php

namespace App\Support\Connectors\AdobePaaS\EntityTrust;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncClient;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncClientException;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;

final class AdobeProductEntityTrustVerifier
{
    public function __construct(
        private readonly AdobeProductCandidateDiscoveryClient $candidateDiscovery,
        private readonly AdobeSafeSyncClient $safeSyncClient,
    ) {}

    public function verifySimpleOrChild(
        AdobePaaSRequestContext $context,
        string $expectedSku,
    ): AdobeProductEntityTrustVerifiedSubject {
        $candidate = $this->candidateDiscovery->discoverSimpleOrChild($context, $expectedSku);

        if ($candidate === null) {
            throw EntityTrustException::candidateNotFound();
        }

        if ($candidate->typeId !== 'simple') {
            throw EntityTrustException::remoteTypeMismatch();
        }

        return $this->verifyCandidate($context, $candidate, 'simple');
    }

    public function verifyConfigurableParent(
        AdobePaaSRequestContext $context,
        string $parentSku,
    ): AdobeProductEntityTrustVerifiedSubject {
        $candidate = $this->candidateDiscovery->discoverConfigurableParent($context, $parentSku);

        if ($candidate === null) {
            throw EntityTrustException::candidateNotFound();
        }

        if ($candidate->typeId !== 'configurable') {
            throw EntityTrustException::remoteTypeMismatch();
        }

        return $this->verifyCandidate($context, $candidate, 'configurable');
    }

    private function verifyCandidate(
        AdobePaaSRequestContext $context,
        AdobeProductCandidateDiscoveryResult $candidate,
        string $expectedType,
    ): AdobeProductEntityTrustVerifiedSubject {
        try {
            $verified = $this->safeSyncClient->readProductWithContext(
                $context,
                $candidate->logicalEntityId,
                $candidate->sku,
            );
        } catch (AdobeSafeSyncClientException $exception) {
            throw EntityTrustException::safeSyncFailure($exception);
        }

        if ($verified->typeId !== $expectedType) {
            throw EntityTrustException::remoteTypeMismatch();
        }

        if ($verified->sku !== $candidate->sku) {
            throw EntityTrustException::candidateUntrusted();
        }

        return new AdobeProductEntityTrustVerifiedSubject(
            logicalEntityId: $verified->logicalEntityId,
            sku: $verified->sku,
            typeId: $verified->typeId,
            name: $verified->name,
            candidate: $candidate,
            verified: $verified,
        );
    }
}
