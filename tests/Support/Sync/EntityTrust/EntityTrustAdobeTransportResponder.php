<?php

namespace Tests\Support\Sync\EntityTrust;

use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncContract;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeProductCommandTestFixtures;

final class EntityTrustAdobeTransportResponder
{
    /** @var array<string, array<string, mixed>> */
    private array $products = [];

    /** @var array<string, list<array{sku: string}>> */
    private array $configurableChildren = [];

    /** @var list<string> */
    public array $recordedMethods = [];

    public function registerProduct(
        string $sku,
        int $logicalEntityId,
        string $typeId = 'simple',
        array $overrides = [],
    ): void {
        $this->products[$sku] = AdobeProductCommandTestFixtures::remoteProductPayload(array_merge([
            'sku' => $sku,
            'id' => $logicalEntityId,
            'type_id' => $typeId,
        ], $overrides));
    }

    /**
     * @param  list<array{sku: string, id?: int, type_id?: string}>  $children
     */
    public function registerConfigurableChildren(string $parentSku, array $children): void
    {
        $this->configurableChildren[$parentSku] = $children;
    }

    public function __invoke(ConnectorOutboundRequest $request, int $count): ConnectorHttpResult
    {
        $uri = (string) $request->request->getUri();
        $method = $request->request->getMethod();
        $this->recordedMethods[] = $method;

        if (str_contains($uri, '/store/storeConfigs')) {
            return new ConnectorHttpResult(200, [], json_encode([
                ['code' => 'default', 'base_currency_code' => 'UAH'],
            ], JSON_THROW_ON_ERROR));
        }

        if (str_contains($uri, '/attribute-sets/sets/list')) {
            return new ConnectorHttpResult(200, [], json_encode([
                'items' => [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
            ], JSON_THROW_ON_ERROR));
        }

        if (str_contains($uri, '/attribute-sets/4/attributes')) {
            return new ConnectorHttpResult(200, [], json_encode([
                ['attribute_id' => 1, 'attribute_code' => 'name', 'frontend_input' => 'text', 'scope' => 'global'],
                ['attribute_id' => 2, 'attribute_code' => 'sku', 'frontend_input' => 'text', 'scope' => 'global'],
                ['attribute_id' => 3, 'attribute_code' => 'status', 'frontend_input' => 'select', 'scope' => 'global', 'options' => [['value' => '1', 'label' => 'Enabled']]],
                ['attribute_id' => 4, 'attribute_code' => 'color', 'frontend_input' => 'select', 'scope' => 'global', 'options' => [['value' => '93', 'label' => 'Blue'], ['value' => '94', 'label' => 'Red']]],
            ], JSON_THROW_ON_ERROR));
        }

        if ($method === 'GET' && str_contains($uri, '/V1/safe-sync/handshake')) {
            return new ConnectorHttpResult(200, [], json_encode([
                'contract_version' => AdobeSafeSyncContract::CONTRACT_VERSION,
                'module_version' => '0.1.0',
                'supported_operation_families' => [
                    AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY,
                ],
            ], JSON_THROW_ON_ERROR));
        }

        if ($method === 'GET' && preg_match('#/V1/safe-sync/products/(\d+)#', $uri, $matches) === 1) {
            $entityId = (int) $matches[1];
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
            $expectedSku = (string) ($query['expectedSku'] ?? '');
            $product = $this->products[$expectedSku] ?? null;

            if ($product === null || (int) ($product['id'] ?? 0) !== $entityId) {
                return new ConnectorHttpResult(404, [], '{"message":"Not found"}');
            }

            return new ConnectorHttpResult(200, [], json_encode([
                'logical_entity_id' => $entityId,
                'sku' => $expectedSku,
                'type_id' => (string) ($product['type_id'] ?? 'simple'),
                'name' => (string) ($product['name'] ?? $expectedSku),
            ], JSON_THROW_ON_ERROR));
        }

        if ($method === 'GET' && str_contains($uri, '/V1/configurable-products/') && str_contains($uri, '/children')) {
            $parentSku = $this->skuFromConfigurableChildrenUri($uri);
            $children = $this->configurableChildren[$parentSku] ?? [];

            return new ConnectorHttpResult(200, [], json_encode($children, JSON_THROW_ON_ERROR));
        }

        if ($method === 'GET' && str_contains($uri, '/V1/products/')) {
            $sku = $this->skuFromProductUri($uri);
            $product = $this->products[$sku] ?? null;

            if ($product === null) {
                return new ConnectorHttpResult(
                    404,
                    [],
                    AdobeProductCommandTestFixtures::trustedMissing404Body($sku),
                );
            }

            return new ConnectorHttpResult(200, [], json_encode($product, JSON_THROW_ON_ERROR));
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return new ConnectorHttpResult(500, [], '{"message":"Unexpected write"}');
        }

        return new ConnectorHttpResult(404, [], '{}');
    }

    public function hasConsequentialWrite(): bool
    {
        return collect($this->recordedMethods)->contains(
            fn (string $method): bool => in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true),
        );
    }

    private function skuFromProductUri(string $uri): string
    {
        if (preg_match('/\/V1\/products\/([^?]+)/', $uri, $matches) !== 1) {
            return 'UNKNOWN';
        }

        return rawurldecode($matches[1]);
    }

    private function skuFromConfigurableChildrenUri(string $uri): string
    {
        if (preg_match('#/V1/configurable-products/([^/]+)/children#', $uri, $matches) !== 1) {
            return 'UNKNOWN';
        }

        return rawurldecode($matches[1]);
    }
}
