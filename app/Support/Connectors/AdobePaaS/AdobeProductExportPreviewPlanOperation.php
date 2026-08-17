<?php

namespace App\Support\Connectors\AdobePaaS;

final readonly class AdobeProductExportPreviewPlanOperation
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public string $operation,
        public array $context = [],
    ) {}

    /**
     * @return array{operation: string, context: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'context' => $this->context,
        ];
    }
}
