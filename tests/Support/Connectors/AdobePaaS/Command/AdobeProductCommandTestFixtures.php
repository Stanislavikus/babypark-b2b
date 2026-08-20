<?php

namespace Tests\Support\Connectors\AdobePaaS\Command;

use App\Services\Pricing\ResolvedPrice;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticFinding;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticOperation;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;

final class AdobeProductCommandTestFixtures
{
    /**
     * @param  array<string, mixed>  $contextOverrides
     * @param  list<AdobeProductExportSemanticFinding>  $findings
     */
    public static function semanticResult(
        array $contextOverrides = [],
        array $findings = [],
        bool $blocking = false,
    ): AdobeProductExportSemanticResult {
        $context = array_merge([
            'product_id' => 'product-1',
            'variant_id' => 'variant-1',
            'sku' => 'SKU-TEST-1',
            'attribute_set_id' => 4,
            'name' => 'Test Product',
            'product_type' => 'simple',
            'visibility' => 'catalog_search',
            'visibility_numeric' => 4,
            'status' => 1,
            'mapped_product_values' => [],
            'mapped_variant_values' => [],
            'resolved_price' => self::serializedResolvedPrice(),
        ], $contextOverrides);

        $operations = $blocking ? [] : [
            new AdobeProductExportSemanticOperation('simple_product', $context),
        ];

        return new AdobeProductExportSemanticResult($findings, $operations);
    }

    public static function blockingSemanticResult(): AdobeProductExportSemanticResult
    {
        return new AdobeProductExportSemanticResult([
            new AdobeProductExportSemanticFinding('missing_name', subject: 'product-1', isBlocking: true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializedResolvedPrice(
        float $effectiveNetPrice = 100.0,
        string $currency = 'UAH',
    ): array {
        $resolved = ResolvedPrice::fromBasePriceCache($effectiveNetPrice, $currency, 20.0);

        return [
            'effective_net_price' => $resolved->effectiveNetPrice,
            'gross_price' => $resolved->grossPrice,
            'currency' => $resolved->currency,
            'vat_rate' => $resolved->vatRate,
            'source' => $resolved->source,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function remoteProductPayload(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'SKU-TEST-1',
            'name' => 'Test Product',
            'attribute_set_id' => 4,
            'type_id' => 'simple',
            'status' => 1,
            'visibility' => 4,
            'price' => 100.0,
            'custom_attributes' => [],
        ], $overrides);
    }

    public static function trustedMissing404Body(string $sku): string
    {
        return json_encode([
            'message' => 'The product with SKU "%1" does not exist.',
            'parameters' => [$sku],
        ], JSON_THROW_ON_ERROR);
    }
}
