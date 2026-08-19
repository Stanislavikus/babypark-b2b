<?php

namespace App\Support\Connectors\AdobePaaS\Semantic;

final readonly class AdobeProductExportSemanticOperation
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $operation,
        public array $context,
    ) {}
}
