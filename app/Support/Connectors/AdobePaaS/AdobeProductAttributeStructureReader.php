<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use Carbon\CarbonImmutable;
use Psr\Http\Message\RequestInterface;

final class AdobeProductAttributeStructureReader
{
    private const int MAX_PAGES = 50;

    private const int MAX_ITEMS = 10_000;

    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobePaaSDiscoveryRequestFactory $discoveryRequestFactory,
        private readonly AdobePaaSDiscoveryResponseMapper $discoveryResponseMapper,
        private readonly AdobeProductExportMetadataRequestFactory $metadataRequestFactory,
        private readonly ConnectorHttpTransport $transport,
    ) {}

    public function read(
        string $workspaceId,
        string $connectorAccountId,
        string $attributeRegistryEndpointPath,
    ): AdobeProductAttributeStructureSnapshot {
        $context = $this->contextFactory->create($workspaceId, $connectorAccountId);
        $rawAttributes = $this->fetchAttributeRegistry($context, $attributeRegistryEndpointPath);
        [$attributes, $options] = $this->normalizeAttributesAndOptions($context, $rawAttributes);
        $attributeSets = $this->fetchAttributeSets($context);
        $setIds = array_column($attributeSets, 'provider_attribute_set_id');
        $attributeGroups = $this->fetchAttributeGroups($context, $setIds);
        $setMemberships = $this->fetchSetMemberships($context, $setIds, $attributes);

        return new AdobeProductAttributeStructureSnapshot(
            storeCode: $context->storeCode,
            capturedAt: CarbonImmutable::now(),
            attributes: $attributes,
            attributeSets: $attributeSets,
            attributeGroups: $attributeGroups,
            setMemberships: $setMemberships,
            options: $options,
        );
    }

    /** @return list<\stdClass> */
    private function fetchAttributeRegistry(AdobePaaSRequestContext $context, string $endpointPath): array
    {
        $items = [];
        $received = 0;
        $stableTotal = null;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $request = $this->discoveryRequestFactory->build(
                $context,
                $endpointPath,
                $page,
                $this->signingContext(),
            );
            $result = $this->send($request);
            $mapped = $this->discoveryResponseMapper->map($result);

            if ($mapped->failure !== null || $mapped->page === null) {
                throw new \RuntimeException('Adobe product attribute registry could not be read.');
            }

            $current = $mapped->page;
            $stableTotal ??= $current->totalCount;

            if ($current->totalCount !== $stableTotal || $stableTotal > self::MAX_ITEMS) {
                throw new \RuntimeException('Adobe product attribute registry pagination was inconsistent.');
            }

            foreach ($current->items as $item) {
                if (! $item instanceof \stdClass) {
                    throw new \RuntimeException('Adobe product attribute registry item must be an object.');
                }

                $items[] = $item;
                $received++;
            }

            if ($received === $stableTotal) {
                return $items;
            }

            if ($current->items === [] || $received > $stableTotal) {
                break;
            }
        }

        throw new \RuntimeException('Adobe product attribute registry pagination was incomplete.');
    }

    /**
     * @param  list<\stdClass>  $rawAttributes
     * @return array{list<array{provider_attribute_id:int, external_field_key:string, frontend_input:?string}>, list<array{provider_attribute_id:int, provider_option_id:string, label:?string}>}
     */
    private function normalizeAttributesAndOptions(AdobePaaSRequestContext $context, array $rawAttributes): array
    {
        $attributes = [];
        $options = [];
        $seenIds = [];
        $seenCodes = [];

        foreach ($rawAttributes as $raw) {
            $providerId = $this->positiveInt($raw->attribute_id ?? null);
            $code = $raw->attribute_code ?? null;

            if ($providerId === null || ! is_string($code) || $code === '' || ! mb_check_encoding($code, 'UTF-8')) {
                throw new \RuntimeException('Adobe product attribute identity was invalid.');
            }

            if (isset($seenIds[$providerId]) || isset($seenCodes[$code])) {
                throw new \RuntimeException('Adobe product attribute identity was duplicated.');
            }

            $seenIds[$providerId] = true;
            $seenCodes[$code] = true;
            $frontendInput = property_exists($raw, 'frontend_input') && is_string($raw->frontend_input)
                ? $raw->frontend_input
                : null;

            $attributes[] = [
                'provider_attribute_id' => $providerId,
                'external_field_key' => $code,
                'frontend_input' => $frontendInput,
            ];

            if (! in_array($frontendInput, ['select', 'multiselect'], true)) {
                continue;
            }

            $rawOptions = property_exists($raw, 'options') && is_array($raw->options) && array_is_list($raw->options)
                ? $raw->options
                : $this->fetchAttributeOptions($context, $code);

            foreach ($this->normalizeOptions($rawOptions) as $option) {
                $options[] = [
                    'provider_attribute_id' => $providerId,
                    'provider_option_id' => $option['provider_option_id'],
                    'label' => $option['label'],
                ];
            }
        }

        usort($attributes, static fn (array $a, array $b): int => $a['provider_attribute_id'] <=> $b['provider_attribute_id']);
        usort($options, static fn (array $a, array $b): int => [$a['provider_attribute_id'], $a['provider_option_id']] <=> [$b['provider_attribute_id'], $b['provider_option_id']]);

        return [$attributes, $options];
    }

    /** @return list<array{provider_attribute_set_id:int, name:string}> */
    private function fetchAttributeSets(AdobePaaSRequestContext $context): array
    {
        $rows = [];
        $seen = [];

        foreach ($this->fetchSearchList($context, '/V1/products/attribute-sets/sets/list', 'attribute sets') as $item) {
            $id = $this->positiveInt($item['attribute_set_id'] ?? null);
            $name = $item['attribute_set_name'] ?? null;

            if ($id === null || ! is_string($name) || $name === '') {
                throw new \RuntimeException('Adobe product attribute set identity was invalid.');
            }

            if (isset($seen[$id])) {
                throw new \RuntimeException('Adobe product attribute set identity was duplicated.');
            }

            $seen[$id] = true;
            $rows[] = ['provider_attribute_set_id' => $id, 'name' => $name];
        }

        if ($rows === []) {
            throw new \RuntimeException('Adobe product attribute set catalogue was empty.');
        }

        usort($rows, static fn (array $a, array $b): int => $a['provider_attribute_set_id'] <=> $b['provider_attribute_set_id']);

        return $rows;
    }

    /**
     * @param  list<int>  $productSetIds
     * @return list<array{provider_attribute_group_id:int, provider_attribute_set_id:int, name:string}>
     */
    private function fetchAttributeGroups(AdobePaaSRequestContext $context, array $productSetIds): array
    {
        $productSetIndex = array_fill_keys($productSetIds, true);
        $rows = [];
        $seen = [];

        foreach ($this->fetchSearchList($context, '/V1/products/attribute-sets/groups/list', 'attribute groups') as $item) {
            $groupId = $this->positiveInt($item['attribute_group_id'] ?? null);
            $setId = $this->positiveInt($item['attribute_set_id'] ?? null);
            $name = $item['attribute_group_name'] ?? null;

            if ($groupId === null || $setId === null || ! is_string($name) || $name === '') {
                throw new \RuntimeException('Adobe product attribute group identity was invalid.');
            }

            if (! isset($productSetIndex[$setId])) {
                continue;
            }

            if (isset($seen[$groupId])) {
                throw new \RuntimeException('Adobe product attribute group identity was duplicated.');
            }

            $seen[$groupId] = true;
            $rows[] = [
                'provider_attribute_group_id' => $groupId,
                'provider_attribute_set_id' => $setId,
                'name' => $name,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['provider_attribute_group_id'] <=> $b['provider_attribute_group_id']);

        return $rows;
    }

    /**
     * @param  list<int>  $productSetIds
     * @param  list<array{provider_attribute_id:int, external_field_key:string, frontend_input:?string}>  $attributes
     * @return list<array{provider_attribute_id:int, provider_attribute_set_id:int}>
     */
    private function fetchSetMemberships(
        AdobePaaSRequestContext $context,
        array $productSetIds,
        array $attributes,
    ): array {
        $knownAttributes = [];

        foreach ($attributes as $attribute) {
            $knownAttributes[$attribute['provider_attribute_id']] = $attribute['external_field_key'];
        }

        $rows = [];
        $seen = [];

        foreach ($productSetIds as $setId) {
            $payload = $this->sendMetadataJson($context, '/V1/products/attribute-sets/'.$setId.'/attributes');

            if (! is_array($payload) || ! array_is_list($payload)) {
                throw new \RuntimeException('Adobe product attribute set membership response must be a list.');
            }

            foreach ($payload as $item) {
                if (! is_array($item)) {
                    throw new \RuntimeException('Adobe product attribute set membership item must be an object.');
                }

                $attributeId = $this->positiveInt($item['attribute_id'] ?? null);
                $code = $item['attribute_code'] ?? null;

                if ($attributeId === null || ! is_string($code) || $code === '') {
                    throw new \RuntimeException('Adobe product attribute set membership identity was invalid.');
                }

                if (! isset($knownAttributes[$attributeId]) || $knownAttributes[$attributeId] !== $code) {
                    throw new \RuntimeException('Adobe product attribute set membership did not match the global registry.');
                }

                $key = $attributeId.':'.$setId;

                if (isset($seen[$key])) {
                    throw new \RuntimeException('Adobe product attribute set membership was duplicated.');
                }

                $seen[$key] = true;
                $rows[] = [
                    'provider_attribute_id' => $attributeId,
                    'provider_attribute_set_id' => $setId,
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['provider_attribute_set_id'], $a['provider_attribute_id']] <=> [$b['provider_attribute_set_id'], $b['provider_attribute_id']]);

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function fetchSearchList(
        AdobePaaSRequestContext $context,
        string $endpointPath,
        string $subject,
    ): array {
        $items = [];
        $received = 0;
        $stableTotal = null;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $payload = $this->sendMetadataJson($context, $endpointPath, $page);

            if (! is_array($payload) || array_is_list($payload)) {
                throw new \RuntimeException('Adobe product '.$subject.' response must be an object.');
            }

            $pageItems = $payload['items'] ?? null;
            $total = $payload['total_count'] ?? null;

            if (! is_array($pageItems) || ! array_is_list($pageItems) || ! is_int($total) || $total < 0) {
                throw new \RuntimeException('Adobe product '.$subject.' pagination response was invalid.');
            }

            $stableTotal ??= $total;

            if ($total !== $stableTotal || $stableTotal > self::MAX_ITEMS) {
                throw new \RuntimeException('Adobe product '.$subject.' pagination was inconsistent.');
            }

            foreach ($pageItems as $item) {
                if (! is_array($item) || array_is_list($item)) {
                    throw new \RuntimeException('Adobe product '.$subject.' item must be an object.');
                }

                $items[] = $item;
                $received++;
            }

            if ($received === $stableTotal) {
                return $items;
            }

            if ($pageItems === [] || $received > $stableTotal) {
                break;
            }
        }

        throw new \RuntimeException('Adobe product '.$subject.' pagination was incomplete.');
    }

    /** @return list<mixed> */
    private function fetchAttributeOptions(AdobePaaSRequestContext $context, string $attributeCode): array
    {
        $payload = $this->sendMetadataJson(
            $context,
            '/V1/products/attributes/'.rawurlencode($attributeCode).'/options',
        );

        if (! is_array($payload) || ! array_is_list($payload)) {
            throw new \RuntimeException('Adobe product attribute options response must be a list.');
        }

        return $payload;
    }

    /**
     * @param  list<mixed>  $rawOptions
     * @return list<array{provider_option_id:string, label:?string}>
     */
    private function normalizeOptions(array $rawOptions): array
    {
        $rows = [];
        $seen = [];

        foreach ($rawOptions as $rawOption) {
            $value = null;
            $label = null;

            if ($rawOption instanceof \stdClass) {
                $value = $rawOption->value ?? null;
                $label = $rawOption->label ?? null;
            } elseif (is_array($rawOption) && ! array_is_list($rawOption)) {
                $value = $rawOption['value'] ?? null;
                $label = $rawOption['label'] ?? null;
            } else {
                throw new \RuntimeException('Adobe product attribute option must be an object.');
            }

            if (! is_string($value) && ! is_int($value)) {
                throw new \RuntimeException('Adobe product attribute option identity was invalid.');
            }

            $providerOptionId = (string) $value;

            if ($providerOptionId === '') {
                continue;
            }

            if ($label !== null && ! is_string($label)) {
                throw new \RuntimeException('Adobe product attribute option label was invalid.');
            }

            if (isset($seen[$providerOptionId])) {
                throw new \RuntimeException('Adobe product attribute option identity was duplicated.');
            }

            $seen[$providerOptionId] = true;
            $rows[] = [
                'provider_option_id' => $providerOptionId,
                'label' => $label,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['provider_option_id'], $b['provider_option_id']));

        return $rows;
    }

    /** @return array<string, mixed>|list<mixed> */
    private function sendMetadataJson(
        AdobePaaSRequestContext $context,
        string $endpointPath,
        ?int $currentPage = null,
    ): array {
        $request = $this->metadataRequestFactory->build(
            $context,
            $endpointPath,
            $this->signingContext(),
            $currentPage,
        );
        $result = $this->send($request);

        if ($result->statusCode !== 200) {
            throw new \RuntimeException('Adobe product structure metadata request failed.');
        }

        try {
            $payload = json_decode($result->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Adobe product structure metadata response was not valid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new \RuntimeException('Adobe product structure metadata response must be JSON object or array.');
        }

        return $payload;
    }

    private function send(RequestInterface $request): ConnectorHttpResult
    {
        try {
            return $this->transport->send(new ConnectorOutboundRequest(
                $request,
                new ConnectorTransportLimits(
                    connectTimeoutSeconds: 10.0,
                    totalTimeoutSeconds: 60.0,
                    maxResponseBodyBytes: 4 * 1024 * 1024,
                ),
            ));
        } catch (ConnectorTransportException $exception) {
            throw new \RuntimeException('Adobe product structure metadata transport failed.', previous: $exception);
        }
    }

    private function signingContext(): OAuth1SigningContext
    {
        return new OAuth1SigningContext(bin2hex(random_bytes(16)), time());
    }

    private function positiveInt(mixed $value): ?int
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
}
