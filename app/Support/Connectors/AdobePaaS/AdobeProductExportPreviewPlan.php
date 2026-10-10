<?php

namespace App\Support\Connectors\AdobePaaS;

final readonly class AdobeProductExportPreviewPlan
{
    /**
     * @param  list<AdobeProductExportPreviewPlanOperation>  $operations
     */
    public function __construct(
        public array $operations,
    ) {}

    /**
     * @return array{operations: list<array{operation: string, context: array<string, scalar|null>}>}
     */
    public function toArray(): array
    {
        return [
            'operations' => array_map(
                static fn (AdobeProductExportPreviewPlanOperation $operation): array => $operation->toArray(),
                $this->operations,
            ),
        ];
    }
}
