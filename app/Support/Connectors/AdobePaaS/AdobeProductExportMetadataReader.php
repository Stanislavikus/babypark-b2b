<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\Exceptions\AdobeProductExportSetupRequiredException;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;

final class AdobeProductExportMetadataReader
{
    private const string ATTRIBUTE_SETS_LIST_ENDPOINT = '/V1/products/attribute-sets/sets/list';

    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductExportMetadataRequestFactory $requestFactory,
        private readonly ConnectorHttpTransport $transport,
    ) {}

    /**
     * @param  list<string>  $relevantAttributeCodes  mapped external_field_keys for this run
     */
    public function read(
        string $workspaceId,
        string $connectorAccountId,
        ?int $attributeSetId = null,
        array $relevantAttributeCodes = [],
    ): AdobeProductExportExecutionMetadata {
        $context = $this->contextFactory->create($workspaceId, $connectorAccountId);
        $attributeSets = $this->fetchAttributeSets($context);

        if ($attributeSets === []) {
            throw new \RuntimeException('Adobe attribute set metadata response did not contain any attribute sets.');
        }

        $selectedAttributeSetId = $this->resolveSelectedAttributeSetId($attributeSets, $attributeSetId);
        $attributes = $this->attributeSetExists($attributeSets, $selectedAttributeSetId)
            ? $this->fetchAttributesForSet($context, $selectedAttributeSetId)
            : [];

        if ($relevantAttributeCodes !== []) {
            $attributes = $this->enrichMappedAttributes(
                $context,
                $attributes,
                $this->normalizeRelevantAttributeCodes($relevantAttributeCodes),
            );
        }

        return new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: $selectedAttributeSetId,
            attributeSets: $attributeSets,
            attributes: $attributes,
        );
    }

    /**
     * @return list<array{attribute_set_id: int, attribute_set_name: string}>
     */
    private function fetchAttributeSets(AdobePaaSRequestContext $context): array
    {
        $payload = $this->sendGet($context, self::ATTRIBUTE_SETS_LIST_ENDPOINT);

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

        usort(
            $attributeSets,
            static fn (array $left, array $right): int => $left['attribute_set_id'] <=> $right['attribute_set_id'],
        );

        return $attributeSets;
    }

    /**
     * @return array<string, AdobeAttributeMetadata>
     */
    private function fetchAttributesForSet(AdobePaaSRequestContext $context, int $attributeSetId): array
    {
        $endpoint = '/V1/products/attribute-sets/'.$attributeSetId.'/attributes';
        $payload = $this->sendGet($context, $endpoint);

        /** @var list<array<string, mixed>> $items */
        $items = $this->parseTopLevelListPayload($payload, 'attribute set attributes');
        $attributes = [];

        foreach ($items as $item) {
            $parsed = $this->parseSetMembershipAttribute($item);

            if ($parsed === null) {
                continue;
            }

            $attributes[$parsed->code] = $parsed;
        }

        return $attributes;
    }

    /**
     * @param  array<string, AdobeAttributeMetadata>  $attributes
     * @param  list<string>  $relevantAttributeCodes
     * @return array<string, AdobeAttributeMetadata>
     */
    private function enrichMappedAttributes(
        AdobePaaSRequestContext $context,
        array $attributes,
        array $relevantAttributeCodes,
    ): array {
        foreach ($relevantAttributeCodes as $code) {
            if (! isset($attributes[$code])) {
                continue;
            }

            $current = $attributes[$code];
            $needsDetail = $current->attributeId === 0
                || $current->frontendInput === ''
                || $current->scope === '';

            if ($needsDetail) {
                $detail = $this->fetchAttributeDetail($context, $code);

                if ($detail !== null) {
                    $current = $this->mergeAttributeMetadata($current, $detail);
                    $attributes[$code] = $current;
                }
            }

            if ($this->requiresOptionFetch($current->frontendInput) && $current->options === []) {
                $attributes[$code] = new AdobeAttributeMetadata(
                    attributeId: $current->attributeId,
                    code: $current->code,
                    frontendInput: $current->frontendInput,
                    scope: $current->scope,
                    options: $this->fetchAttributeOptions($context, $code),
                );
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function parseSetMembershipAttribute(array $item): ?AdobeAttributeMetadata
    {
        $attributeCode = $item['attribute_code'] ?? null;

        if (! is_string($attributeCode) || $attributeCode === '') {
            return null;
        }

        $attributeId = $this->parsePositiveInt($item['attribute_id'] ?? null) ?? 0;
        $frontendInput = is_string($item['frontend_input'] ?? null) ? $item['frontend_input'] : '';
        $scope = is_string($item['scope'] ?? null) ? $item['scope'] : '';

        return new AdobeAttributeMetadata(
            attributeId: $attributeId,
            code: $attributeCode,
            frontendInput: $frontendInput,
            scope: $scope,
            options: $this->normalizeInlineOptions($item['options'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchAttributeDetail(AdobePaaSRequestContext $context, string $attributeCode): ?array
    {
        $endpoint = '/V1/products/attributes/'.rawurlencode($attributeCode);
        $payload = $this->sendGet($context, $endpoint);

        if (! is_array($payload) || array_is_list($payload)) {
            return null;
        }

        $code = $payload['attribute_code'] ?? null;

        if (! is_string($code) || $code === '') {
            return null;
        }

        return $payload;
    }

    private function mergeAttributeMetadata(AdobeAttributeMetadata $current, array $detail): AdobeAttributeMetadata
    {
        $attributeId = $this->parsePositiveInt($detail['attribute_id'] ?? null) ?? $current->attributeId;
        $frontendInput = is_string($detail['frontend_input'] ?? null) && $detail['frontend_input'] !== ''
            ? $detail['frontend_input']
            : $current->frontendInput;
        $scope = is_string($detail['scope'] ?? null) && $detail['scope'] !== ''
            ? $detail['scope']
            : $current->scope;
        $options = $current->options;

        if ($options === [] && isset($detail['options'])) {
            $options = $this->normalizeInlineOptions($detail['options']);
        }

        return new AdobeAttributeMetadata(
            attributeId: $attributeId,
            code: $current->code,
            frontendInput: $frontendInput,
            scope: $scope,
            options: $options,
        );
    }

    /**
     * @return array<string, string>
     */
    private function fetchAttributeOptions(AdobePaaSRequestContext $context, string $attributeCode): array
    {
        $endpoint = '/V1/products/attributes/'.rawurlencode($attributeCode).'/options';
        $payload = $this->sendGet($context, $endpoint);

        /** @var list<array<string, mixed>> $items */
        $items = $this->parseTopLevelListPayload($payload, 'attribute options');

        return $this->normalizeOptionItems($items);
    }

    /**
     * @param  list<array{attribute_set_id: int, attribute_set_name: string}>  $attributeSets
     */
    private function resolveSelectedAttributeSetId(array $attributeSets, ?int $attributeSetId): int
    {
        if ($attributeSetId !== null) {
            return $attributeSetId;
        }

        if (count($attributeSets) === 1) {
            return $attributeSets[0]['attribute_set_id'];
        }

        throw new AdobeProductExportSetupRequiredException($attributeSets);
    }

    /**
     * @param  list<array{attribute_set_id: int, attribute_set_name: string}>  $attributeSets
     */
    private function attributeSetExists(array $attributeSets, int $attributeSetId): bool
    {
        foreach ($attributeSets as $attributeSet) {
            if ($attributeSet['attribute_set_id'] === $attributeSetId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function normalizeRelevantAttributeCodes(array $codes): array
    {
        $normalized = [];

        foreach ($codes as $code) {
            if (is_string($code) && $code !== '') {
                $normalized[] = $code;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function requiresOptionFetch(string $frontendInput): bool
    {
        return in_array($frontendInput, ['select', 'multiselect'], true);
    }

    private function parsePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function sendGet(AdobePaaSRequestContext $context, string $endpointPath): array
    {
        $signingContext = new OAuth1SigningContext(
            bin2hex(random_bytes(16)),
            time(),
        );

        $request = $this->requestFactory->build(
            $context,
            $endpointPath,
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
                'Adobe product export metadata could not be retrieved.',
                previous: $exception,
            );
        }

        $payload = json_decode($httpResult->body, true);

        if (! is_array($payload)) {
            throw new \RuntimeException('Adobe product export metadata response was not valid JSON.');
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseTopLevelListPayload(array $payload, string $subject): array
    {
        if (! array_is_list($payload)) {
            throw new \RuntimeException(
                'Adobe product export '.$subject.' response must be a top-level JSON array.',
            );
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeInlineOptions(mixed $rawOptions): array
    {
        if (! is_array($rawOptions)) {
            return [];
        }

        if (! array_is_list($rawOptions)) {
            return [];
        }

        return $this->normalizeOptionItems($rawOptions);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, string>
     */
    private function normalizeOptionItems(array $items): array
    {
        $options = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $value = $item['value'] ?? null;
            $label = $item['label'] ?? null;

            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            $normalizedValue = (string) $value;

            if ($normalizedValue === '') {
                continue;
            }

            $options[$normalizedValue] = is_string($label) ? $label : $normalizedValue;
        }

        return $options;
    }
}
