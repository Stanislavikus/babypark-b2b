<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

final readonly class AdobeSafeSyncHandshake
{
    /**
     * @param  list<string>  $supportedOperationFamilies
     */
    public function __construct(
        public string $contractVersion,
        public string $moduleVersion,
        public array $supportedOperationFamilies,
        public ?string $applicationVersion = null,
        public ?string $phpVersion = null,
    ) {}
}
