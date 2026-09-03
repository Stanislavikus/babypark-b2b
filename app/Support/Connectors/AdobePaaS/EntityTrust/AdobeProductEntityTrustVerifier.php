<?php

namespace App\Support\Connectors\AdobePaaS\EntityTrust;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductDocumentReadException;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductDocumentReader;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncVerifiedProduct;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;

final class AdobeProductEntityTrustVerifier
{
    public function __construct(
        private readonly AdobeProductCandidateDiscoveryClient $candidateDiscovery,
        private readonly AdobeProductDocumentReader $productDocumentReader,
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
            $document = $this->productDocumentReader->readWithContext($context, $candidate->sku);
        } catch (AdobeProductDocumentReadException $exception) {
            throw EntityTrustException::safeSyncFailure($exception);
        }

        if ($document->typeId !== $expectedType) {
            throw EntityTrustException::remoteTypeMismatch();
        }

        if ($document->sku !== $candidate->sku || $document->logicalEntityId !== $candidate->logicalEntityId) {
            throw EntityTrustException::candidateUntrusted();
        }

        $name = $document->originalPayload['name'] ?? null;

        return new AdobeProductEntityTrustVerifiedSubject(
            logicalEntityId: $document->logicalEntityId,
            sku: $document->sku,
            typeId: $document->typeId,
            name: is_string($name) ? $name : '',
            candidate: $candidate,
            verified: new AdobeSafeSyncVerifiedProduct(
                logicalEntityId: $document->logicalEntityId,
                sku: $document->sku,
                typeId: $document->typeId,
                name: is_string($name) ? $name : '',
            ),
        );
    }
}
