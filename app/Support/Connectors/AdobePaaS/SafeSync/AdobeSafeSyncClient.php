<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
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
        ?AdobeSafeSyncHandshakeParser $handshakeParser = null,
    ) {
        $this->handshakeParser = $handshakeParser ?? new AdobeSafeSyncHandshakeParser;
    }

    private readonly AdobeSafeSyncHandshakeParser $handshakeParser;

    public function handshake(string $workspaceId, string $connectorAccountId): AdobeSafeSyncHandshake
    {
        $context = $this->contextFactory->create($workspaceId, $connectorAccountId);

        return $this->handshakeWithContext($context);
    }

    public function handshakeWithContext(#[\SensitiveParameter] AdobePaaSRequestContext $context): AdobeSafeSyncHandshake
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
        #[\SensitiveParameter] AdobePaaSRequestContext $context,
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

    public function writeSimpleProduct(
        string $workspaceId,
        string $connectorAccountId,
        int $logicalEntityId,
        AdobeSafeSyncSimpleProductWriteRequest $payload,
    ): AdobeSafeSyncSimpleProductWriteResult {
        $context = $this->contextFactory->create($workspaceId, $connectorAccountId);

        return $this->writeSimpleProductWithContext($context, $logicalEntityId, $payload);
    }

    public function writeSimpleProductWithContext(
        #[\SensitiveParameter] AdobePaaSRequestContext $context,
        int $logicalEntityId,
        AdobeSafeSyncSimpleProductWriteRequest $payload,
    ): AdobeSafeSyncSimpleProductWriteResult {
        $request = $this->requestFactory->buildWriteSimpleProduct(
            $context,
            $logicalEntityId,
            $payload,
            $this->newSigningContext(),
        );

        try {
            $result = $this->transport->send(new ConnectorOutboundRequest(
                $request,
                new ConnectorTransportLimits(
                    connectTimeoutSeconds: 10.0,
                    totalTimeoutSeconds: 30.0,
                    maxResponseBodyBytes: AdobeSafeSyncContract::SIMPLE_PRODUCT_WRITE_MAX_RESPONSE_BYTES,
                ),
            ));
        } catch (ConnectorTransportException) {
            return $this->unknownWriteResult(
                'safe_sync_transport_ambiguous',
                $logicalEntityId,
                $payload->expectedSku,
            );
        }

        try {
            return $this->parseSimpleProductWriteResult($result, $logicalEntityId, $payload->expectedSku);
        } catch (AdobeSafeSyncClientException) {
            return $this->unknownWriteResult(
                'safe_sync_bridge_response_ambiguous',
                $logicalEntityId,
                $payload->expectedSku,
            );
        }
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
        if ($result->statusCode !== 200) {
            throw new AdobeSafeSyncClientException(sprintf('Safe Sync returned unexpected HTTP status %d.', $result->statusCode));
        }

        $handshake = $this->handshakeParser->parse($result->body);

        if ($handshake->contractVersion !== AdobeSafeSyncContract::CONTRACT_VERSION) {
            throw new AdobeSafeSyncClientException('Safe Sync contract version is not supported.');
        }

        $this->assertRequiredOperationFamilies(
            $handshake->supportedOperationFamilies,
            AdobeSafeSyncRequiredOperation::ProductRead,
        );

        return $handshake;
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

    private function parseSimpleProductWriteResult(
        ConnectorHttpResult $result,
        int $expectedLogicalEntityId,
        string $expectedSku,
    ): AdobeSafeSyncSimpleProductWriteResult {
        $payload = $this->decodeJsonObject($result, expectedStatusCode: 200);

        $logicalEntityId = $this->requireInt($payload, 'logical_entity_id');
        $sku = $this->requireString($payload, 'sku');
        $reasonCode = $this->requireString($payload, 'reason_code');
        $appliedState = $this->requireString($payload, 'applied_state');
        $postconditionVerified = $this->requireBool($payload, 'postcondition_verified');
        $consequentialWriteAttempts = $this->requireInt($payload, 'consequential_write_attempts');
        $warningCodes = $this->requireStringList($payload, 'warning_codes');

        if ($logicalEntityId !== $expectedLogicalEntityId) {
            throw new AdobeSafeSyncClientException('Safe Sync logical entity identity mismatch.');
        }

        if ($sku !== $expectedSku) {
            throw new AdobeSafeSyncClientException('Safe Sync SKU mismatch.');
        }

        if (! in_array($consequentialWriteAttempts, [0, 1], true)) {
            throw new AdobeSafeSyncClientException('Safe Sync response field `consequential_write_attempts` is invalid.');
        }

        try {
            $knowledge = AdobeProductAppliedStateKnowledge::from($appliedState);
        } catch (\ValueError $exception) {
            throw new AdobeSafeSyncClientException('Safe Sync response field `applied_state` is invalid.', 0, $exception);
        }

        if (
            $knowledge === AdobeProductAppliedStateKnowledge::KnownApplied
            && ($consequentialWriteAttempts !== 1 || $postconditionVerified !== true)
        ) {
            throw new AdobeSafeSyncClientException('Safe Sync known-applied write response is internally inconsistent.');
        }

        return new AdobeSafeSyncSimpleProductWriteResult(
            $knowledge,
            $reasonCode,
            $logicalEntityId,
            $sku,
            $postconditionVerified,
            $consequentialWriteAttempts,
            $warningCodes,
        );
    }

    private function unknownWriteResult(
        string $reasonCode,
        int $logicalEntityId,
        string $expectedSku,
    ): AdobeSafeSyncSimpleProductWriteResult {
        return new AdobeSafeSyncSimpleProductWriteResult(
            AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            $reasonCode,
            $logicalEntityId,
            $expectedSku,
            false,
            1,
            [],
        );
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
     */
    private function requireBool(array $payload, string $key): bool
    {
        if (! array_key_exists($key, $payload) || ! is_bool($payload[$key])) {
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
    private function assertRequiredOperationFamilies(array $supportedOperationFamilies, AdobeSafeSyncRequiredOperation $operation): void
    {
        if (array_diff($operation->requiredFamilies(), $supportedOperationFamilies) !== []) {
            throw new AdobeSafeSyncClientException('Safe Sync required operation family is not supported.');
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
