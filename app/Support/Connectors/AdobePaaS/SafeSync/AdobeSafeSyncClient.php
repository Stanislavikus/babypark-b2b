<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use JsonException;
use Psr\Http\Message\RequestInterface;

final class AdobeSafeSyncClient
{
    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeSafeSyncRequestFactory $requestFactory,
        private readonly ConnectorHttpTransport $transport,
    ) {}

    public function handshake(string $workspaceId, string $connectorAccountId): AdobeSafeSyncHandshake
    {
        $context = $this->contextFactory->create($workspaceId, $connectorAccountId);

        return $this->handshakeWithContext($context);
    }

    public function handshakeWithContext(AdobePaaSRequestContext $context): AdobeSafeSyncHandshake
    {
        $result = $this->send(
            $this->requestFactory->buildHandshake($context, $this->newSigningContext()),
            AdobeSafeSyncContract::HANDSHAKE_MAX_RESPONSE_BYTES,
        );

        return $this->parseHandshake($result);
    }

    public function readProduct(
        string $workspaceId,
        string $connectorAccountId,
        int $logicalEntityId,
        string $expectedSku,
    ): AdobeSafeSyncVerifiedProduct {
        $context = $this->contextFactory->create($workspaceId, $connectorAccountId);

        return $this->readProductWithContext($context, $logicalEntityId, $expectedSku);
    }

    public function readProductWithContext(
        AdobePaaSRequestContext $context,
        int $logicalEntityId,
        string $expectedSku,
    ): AdobeSafeSyncVerifiedProduct {
        $result = $this->send(
            $this->requestFactory->buildReadProduct(
                $context,
                $logicalEntityId,
                $expectedSku,
                $this->newSigningContext(),
            ),
            AdobeSafeSyncContract::PRODUCT_READ_MAX_RESPONSE_BYTES,
        );

        return $this->parseVerifiedProduct($result, $logicalEntityId, $expectedSku);
    }

    private function send(RequestInterface $request, int $maxResponseBodyBytes): ConnectorHttpResult
    {
        try {
            return $this->transport->send(new ConnectorOutboundRequest(
                $request,
                new ConnectorTransportLimits(
                    connectTimeoutSeconds: 10.0,
                    totalTimeoutSeconds: 30.0,
                    maxResponseBodyBytes: $maxResponseBodyBytes,
                ),
            ));
        } catch (ConnectorTransportException $exception) {
            throw new AdobeSafeSyncClientException('Safe Sync request failed.', 0, $exception);
        }
    }

    private function parseHandshake(ConnectorHttpResult $result): AdobeSafeSyncHandshake
    {
        $payload = $this->decodeJsonObject($result, expectedStatusCode: 200);

        $contractVersion = $this->requireString($payload, 'contract_version');
        $moduleVersion = $this->requireString($payload, 'module_version');
        $supportedOperationFamilies = $this->requireStringList($payload, 'supported_operation_families');

        if ($contractVersion !== AdobeSafeSyncContract::CONTRACT_VERSION) {
            throw new AdobeSafeSyncClientException('Safe Sync contract version is not supported.');
        }

        $this->assertSupportedOperationFamilies($supportedOperationFamilies);

        return new AdobeSafeSyncHandshake(
            $contractVersion,
            $moduleVersion,
            $supportedOperationFamilies,
        );
    }

    private function parseVerifiedProduct(
        ConnectorHttpResult $result,
        int $expectedLogicalEntityId,
        string $expectedSku,
    ): AdobeSafeSyncVerifiedProduct {
        $payload = $this->decodeJsonObject($result, expectedStatusCode: 200);

        $logicalEntityId = $this->requireInt($payload, 'logical_entity_id');
        $sku = $this->requireString($payload, 'sku');
        $typeId = $this->requireString($payload, 'type_id');
        $name = $this->requireString($payload, 'name');

        if ($logicalEntityId !== $expectedLogicalEntityId) {
            throw new AdobeSafeSyncClientException('Safe Sync logical entity identity mismatch.');
        }

        if ($sku !== $expectedSku) {
            throw new AdobeSafeSyncClientException('Safe Sync SKU mismatch.');
        }

        return new AdobeSafeSyncVerifiedProduct($logicalEntityId, $sku, $typeId, $name);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(ConnectorHttpResult $result, int $expectedStatusCode): array
    {
        if ($result->statusCode !== $expectedStatusCode) {
            throw new AdobeSafeSyncClientException(
                sprintf('Safe Sync returned unexpected HTTP status %d.', $result->statusCode),
            );
        }

        try {
            $payload = json_decode($result->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AdobeSafeSyncClientException('Safe Sync returned malformed JSON.', 0, $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new AdobeSafeSyncClientException('Safe Sync response must be a JSON object.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requireString(array $payload, string $key): string
    {
        if (! array_key_exists($key, $payload) || ! is_string($payload[$key]) || $payload[$key] === '') {
            throw new AdobeSafeSyncClientException(sprintf('Safe Sync response field `%s` is invalid.', $key));
        }

        return $payload[$key];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requireInt(array $payload, string $key): int
    {
        if (! array_key_exists($key, $payload) || ! is_int($payload[$key])) {
            throw new AdobeSafeSyncClientException(sprintf('Safe Sync response field `%s` is invalid.', $key));
        }

        return $payload[$key];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function requireStringList(array $payload, string $key): array
    {
        if (! array_key_exists($key, $payload) || ! is_array($payload[$key]) || array_is_list($payload[$key]) === false) {
            throw new AdobeSafeSyncClientException(sprintf('Safe Sync response field `%s` is invalid.', $key));
        }

        $values = [];

        foreach ($payload[$key] as $value) {
            if (! is_string($value) || $value === '') {
                throw new AdobeSafeSyncClientException(sprintf('Safe Sync response field `%s` is invalid.', $key));
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param  list<string>  $supportedOperationFamilies
     */
    private function assertSupportedOperationFamilies(array $supportedOperationFamilies): void
    {
        $allowed = [
            AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY,
        ];

        foreach ($supportedOperationFamilies as $family) {
            if (! in_array($family, $allowed, true)) {
                throw new AdobeSafeSyncClientException('Safe Sync advertised an unknown operation family.');
            }
        }

        if (! in_array(AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY, $supportedOperationFamilies, true)) {
            throw new AdobeSafeSyncClientException('Safe Sync product verification family is not supported.');
        }
    }

    private function newSigningContext(): OAuth1SigningContext
    {
        return new OAuth1SigningContext(
            bin2hex(random_bytes(16)),
            time(),
        );
    }
}
