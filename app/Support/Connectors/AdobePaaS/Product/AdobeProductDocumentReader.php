<?php

namespace App\Support\Connectors\AdobePaaS\Product;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateClient;
use JsonException;

final class AdobeProductDocumentReader
{
    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductRemoteStateClient $remoteStateClient,
    ) {}

    public function read(
        string $workspaceId,
        string $connectorAccountId,
        string $sku,
    ): AdobeProductDocument {
        return $this->readWithContext(
            $this->contextFactory->create($workspaceId, $connectorAccountId),
            $sku,
        );
    }

    public function readWithContext(
        AdobePaaSRequestContext $context,
        string $sku,
    ): AdobeProductDocument {
        [$httpResult, $transportException] = $this->remoteStateClient->sendReadOnlyGetWithContext($context, $sku);

        if ($transportException !== null) {
            throw new AdobeProductDocumentReadException('Magento Product document read transport failed.', 0, $transportException);
        }

        if ($httpResult === null) {
            throw new AdobeProductDocumentReadException('Magento Product document read returned no HTTP result.');
        }

        if ($httpResult->statusCode !== 200) {
            throw new AdobeProductDocumentReadException(
                sprintf('Magento Product document read returned HTTP %d.', $httpResult->statusCode),
            );
        }

        try {
            $payload = json_decode($httpResult->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AdobeProductDocumentReadException('Magento Product document read returned invalid JSON.', 0, $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new AdobeProductDocumentReadException('Magento Product document read must return a JSON object.');
        }

        return AdobeProductDocument::fromPayload($payload);
    }
}
