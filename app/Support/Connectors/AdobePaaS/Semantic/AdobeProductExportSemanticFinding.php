<?php

namespace App\Support\Connectors\AdobePaaS\Semantic;

final readonly class AdobeProductExportSemanticFinding
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public string $code,
        public string $subject,
        public array $context = [],
        public bool $isBlocking = true,
    ) {}
}
