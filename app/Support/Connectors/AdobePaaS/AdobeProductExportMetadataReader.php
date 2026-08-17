<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;

final class AdobeProductExportMetadataReader
{
    private const string ATTRIBUTE_SETS_ENDPOINT = '/V1/products/attribute-sets/sets';

    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductExportMetadataRequestFactory $requestFactory,
        private readonly ConnectorHttpTransport $transport,
    ) {}

    public function read(
        string $workspaceId,
        string $connectorAccountId,
        ?int $preferredAttributeSetId = null,
    ): AdobeProductExportExecutionMetadata {
        $context = $this->contextFactory->create($workspaceId, $connectorAccountId);
        $signingContext = new OAuth1SigningContext(
            bin2hex(random_bytes(16)),
            time(),
        );

        $request = $this->requestFactory->build(
            $context,
            self::ATTRIBUTE_SETS_ENDPOINT,
            $signingContext,
        );

        $outboundRequest = new ConnectorOutboundRequest(
            $request,
            new ConnectorTransportLimits(
                connectTimeoutSeconds: 10.0,
                totalTimeoutSeconds: 60.0,
                maxResponseBodyBytes: 2 * 1024 * 1024,
            ),
        );

        try {
            $httpResult = $this->transport->send($outboundRequest);
        } catch (ConnectorTransportException $exception) {
            throw new \RuntimeException(
                'Adobe attribute set metadata could not be retrieved.',
                previous: $exception,
            );
        }

        $payload = json_decode($httpResult->body, true);

        if (! is_array($payload)) {
            throw new \RuntimeException('Adobe attribute set metadata response was not valid JSON.');
        }

        /** @var list<array<string, mixed>> $items */
        $items = $payload['items'] ?? [];

        $attributeSets = [];

        foreach ($items as $item) {
            $attributeSetId = $item['attribute_set_id'] ?? null;
            $attributeSetName = $item['attribute_set_name'] ?? null;

            if (! is_int($attributeSetId) && ! (is_string($attributeSetId) && ctype_digit($attributeSetId))) {
                continue;
            }

            $attributeSets[] = [
                'attribute_set_id' => (int) $attributeSetId,
                'attribute_set_name' => is_string($attributeSetName) ? $attributeSetName : 'Attribute Set '.$attributeSetId,
            ];
        }

        if ($attributeSets === []) {
            throw new \RuntimeException('Adobe attribute set metadata response did not contain any attribute sets.');
        }

        usort(
            $attributeSets,
            static fn (array $left, array $right): int => $left['attribute_set_id'] <=> $right['attribute_set_id'],
        );

        $selectedAttributeSetId = $this->resolveSelectedAttributeSetId($attributeSets, $preferredAttributeSetId);

        return new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: $selectedAttributeSetId,
            attributeSets: $attributeSets,
        );
    }

    /**
     * @param  list<array{attribute_set_id: int, attribute_set_name: string}>  $attributeSets
     */
    private function resolveSelectedAttributeSetId(array $attributeSets, ?int $preferredAttributeSetId): int
    {
        if ($preferredAttributeSetId !== null) {
            foreach ($attributeSets as $attributeSet) {
                if ($attributeSet['attribute_set_id'] === $preferredAttributeSetId) {
                    return $preferredAttributeSetId;
                }
            }
        }

        foreach ($attributeSets as $attributeSet) {
            if (strcasecmp($attributeSet['attribute_set_name'], 'Default') === 0) {
                return $attributeSet['attribute_set_id'];
            }
        }

        return $attributeSets[0]['attribute_set_id'];
    }
}
