<?php

namespace App\Support\Connectors\AdobePaaS;

final readonly class AdobeProductExportPreviewPlanOperation
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $operation,
        public array $context = [],
    ) {}

    /**
     * @return array{operation: string, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'context' => $this->context,
        ];
    }
}
