<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorTransportException;

final class AdobeProductRemoteGetClassifier
{
    public function __construct(
        private readonly AdobeProductRemoteStateNormalizer $normalizer,
    ) {}

    public function classify(
        string $requestedSku,
        ?ConnectorHttpResult $httpResult,
        ?ConnectorTransportException $transportException = null,
    ): AdobeProductRemoteGetResult {
        if ($transportException !== null || $httpResult === null) {
            return new AdobeProductRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
        }

        if ($httpResult->statusCode === 200) {
            $payload = json_decode($httpResult->body, true);

            if (! is_array($payload)) {
                return new AdobeProductRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
            }

            $observed = $this->normalizer->normalize($payload, $requestedSku);

            if ($observed === null) {
                return new AdobeProductRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
            }

            return new AdobeProductRemoteGetResult(
                AdobeProductRemoteGetClassification::Found,
                $observed,
                $this->mediaRoleLabelMaterializationSafe($payload),
            );
        }

        if ($httpResult->statusCode === 404 && $this->isTrustedProductMissingEvidence($requestedSku, $httpResult->body)) {
            return new AdobeProductRemoteGetResult(AdobeProductRemoteGetClassification::TrustedKnownMissing);
        }

        return new AdobeProductRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
    }

    public function classifyParent(
        string $requestedSku,
        ?ConnectorHttpResult $httpResult,
        ?ConnectorTransportException $transportException = null,
    ): AdobeProductParentRemoteGetResult {
        if ($transportException !== null || $httpResult === null) {
            return new AdobeProductParentRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
        }

        if ($httpResult->statusCode === 200) {
            $payload = json_decode($httpResult->body, true);

            if (! is_array($payload)) {
                return new AdobeProductParentRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
            }

            $observed = $this->normalizer->normalizeParent($payload, $requestedSku);

            if ($observed === null) {
                return new AdobeProductParentRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
            }

            return new AdobeProductParentRemoteGetResult(
                AdobeProductRemoteGetClassification::Found,
                $observed,
                $this->mediaRoleLabelMaterializationSafe($payload),
            );
        }

        if ($httpResult->statusCode === 404 && $this->isTrustedProductMissingEvidence($requestedSku, $httpResult->body)) {
            return new AdobeProductParentRemoteGetResult(AdobeProductRemoteGetClassification::TrustedKnownMissing);
        }

        return new AdobeProductParentRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
    }

    /** @param array<string, mixed> $payload */
    private function mediaRoleLabelMaterializationSafe(array $payload): ?bool
    {
        $entries = $payload['media_gallery_entries'] ?? null;
        $customAttributes = $payload['custom_attributes'] ?? null;

        if (! is_array($entries) || ! is_array($customAttributes)) {
            return null;
        }

        $customAttributeValues = [];

        foreach ($customAttributes as $attribute) {
            if (! is_array($attribute)) {
                return null;
            }

            $code = $attribute['attribute_code'] ?? null;
            if (! is_string($code) || $code === '') {
                return null;
            }

            $customAttributeValues[$code] = $attribute['value'] ?? null;
        }

        $roleLabelAttributes = [
            'image' => 'image_label',
            'small_image' => 'small_image_label',
            'thumbnail' => 'thumbnail_label',
        ];

        $assignedRoles = array_fill_keys(array_keys($roleLabelAttributes), false);

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                return null;
            }

            $label = $entry['label'] ?? null;
            if ($label !== null && ! is_string($label)) {
                return null;
            }

            $types = $entry['types'] ?? null;
            if (! is_array($types)) {
                return null;
            }

            foreach ($roleLabelAttributes as $role => $labelAttribute) {
                if (! in_array($role, $types, true)) {
                    continue;
                }

                $assignedRoles[$role] = true;
                $hasProjection = array_key_exists($labelAttribute, $customAttributeValues);

                if ($label === null || $label === '') {
                    if ($hasProjection) {
                        return false;
                    }

                    continue;
                }

                if (! $hasProjection || $customAttributeValues[$labelAttribute] !== $label) {
                    return false;
                }
            }
        }

        foreach ($roleLabelAttributes as $role => $labelAttribute) {
            if (! $assignedRoles[$role] && array_key_exists($labelAttribute, $customAttributeValues)) {
                return false;
            }
        }

        return true;
    }

    private function isTrustedProductMissingEvidence(string $requestedSku, string $body): bool
    {
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return false;
        }

        $message = $payload['message'] ?? null;
        $parameters = $payload['parameters'] ?? null;

        if (! is_string($message) || $message === '') {
            return false;
        }

        if (! is_array($parameters)) {
            return false;
        }

        foreach ($parameters as $parameter) {
            if (is_string($parameter) && $parameter === $requestedSku) {
                return true;
            }
        }

        return false;
    }
}
