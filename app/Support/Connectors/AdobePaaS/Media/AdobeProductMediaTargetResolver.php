<?php

namespace App\Support\Connectors\AdobePaaS\Media;

use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableParentSkuGenerator;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticOperation;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;

final class AdobeProductMediaTargetResolver
{
    public function __construct(
        private readonly AdobeConfigurableParentSkuGenerator $parentSkuGenerator,
    ) {}

    /**
     * @return array{sku: string, label: string}|null
     */
    public function resolve(
        string $workspaceId,
        string $productId,
        AdobeProductExportSemanticResult $semanticResult,
        bool $isConfigurablePath,
    ): ?array {
        if ($isConfigurablePath) {
            return $this->resolveConfigurable($workspaceId, $productId, $semanticResult);
        }

        return $this->resolveSimple($semanticResult);
    }

    /**
     * @return array{sku: string, label: string}|null
     */
    private function resolveSimple(AdobeProductExportSemanticResult $semanticResult): ?array
    {
        foreach ($semanticResult->operations as $operation) {
            if ($operation->operation !== 'simple_product') {
                continue;
            }

            $sku = $operation->context['sku'] ?? null;
            $name = $operation->context['name'] ?? null;

            if (! is_string($sku) || $sku === '' || ! is_string($name) || $name === '') {
                return null;
            }

            return ['sku' => $sku, 'label' => $name];
        }

        return null;
    }

    /**
     * @return array{sku: string, label: string}|null
     */
    private function resolveConfigurable(
        string $workspaceId,
        string $productId,
        AdobeProductExportSemanticResult $semanticResult,
    ): ?array {
        if (! ctype_digit($productId)) {
            return null;
        }

        $parentOperation = $this->findOperation($semanticResult, 'configurable_parent');

        if ($parentOperation === null) {
            return null;
        }

        $name = $parentOperation->context['name'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        return [
            'sku' => $this->parentSkuGenerator->generate($workspaceId, (int) $productId),
            'label' => $name,
        ];
    }

    private function findOperation(
        AdobeProductExportSemanticResult $semanticResult,
        string $operationType,
    ): ?AdobeProductExportSemanticOperation {
        foreach ($semanticResult->operations as $operation) {
            if ($operation->operation === $operationType) {
                return $operation;
            }
        }

        return null;
    }
}
