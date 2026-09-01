<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

final readonly class MagentoSafeSyncPackageRequirements
{
    public function __construct(
        public ?string $phpConstraint,
        public ?string $magentoFrameworkConstraint,
        public ?string $magentoCatalogConstraint,
    ) {}
}
