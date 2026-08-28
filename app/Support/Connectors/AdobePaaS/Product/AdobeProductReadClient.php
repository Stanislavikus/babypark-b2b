<?php

namespace App\Support\Connectors\AdobePaaS\Product;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use JsonException;

final class AdobeProductReadClient
{
    private const int MAX_RESPONSE_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductReadRequestFactory $requestFactory,
        private readonly ConnectorHttpTransport $transport,
    ) {}

    public function read(
        string $workspaceId,
        string $connectorAccountId,
        int $expectedLogicalEntityId,
        string $expectedSku,
    ): AdobeProductDocument {
        $context = $this->contextFactory->create($workspaceId, $connectorAccountId);

        return $this->readWithContext($context, $expectedLogicalEntityId, $expectedSku);
    }

    public function readWithContext(
        #[\SensitiveParameter] AdobePaaSRequestContext $context,
        int $expectedLogicalEntityId,
        string $expectedSku,
    ): AdobeProductDocument {
        $request = $this->requestFactory->build(
            $context,
            $expectedSku,
            new OAuth1SigningContext(bin2hex(random_bytes(16)), time()),
        );

        try {
            $result = $this->transport->send(new ConnectorOutboundRequest(
                $request,
                new ConnectorTransportLimits(
                    connectTimeoutSeconds: 10.0,
                    totalTimeoutSeconds: 30.0,
                    maxResponseBodyBytes: self::MAX_RESPONSE_BYTES,
                ),
            ));
        } catch (ConnectorTransportException $exception) {
            throw new AdobeProductReadException('Magento Product read failed.', 0, $exception);
        }

        if ($result->statusCode !== 200) {
            throw new AdobeProductReadException(
                sprintf('Magento Product read returned unexpected HTTP status %d.', $result->statusCode),
            );
        }

        try {
            $payload = json_decode($result->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AdobeProductReadException('Magento Product read returned malformed JSON.', 0, $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new AdobeProductReadException('Magento Product response must be a JSON object.');
        }

        return AdobeProductDocument::fromPayload($payload, $expectedLogicalEntityId, $expectedSku);
    }
}
