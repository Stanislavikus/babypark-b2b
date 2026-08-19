<?php

namespace App\Support\Connectors\AdobePaaS\Semantic;

final readonly class AdobeProductExportSemanticResult
{
    /**
     * @param  list<AdobeProductExportSemanticFinding>  $findings
     * @param  list<AdobeProductExportSemanticOperation>  $operations
     */
    public function __construct(
        public array $findings,
        public array $operations = [],
    ) {}

    public function hasBlockingFindings(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->isBlocking) {
                return true;
            }
        }

        return false;
    }

    public function hasFindingCode(string $code): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->code === $code) {
                return true;
            }
        }

        return false;
    }
}
