<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

final readonly class MagentoEnvironmentCertificationAssessment
{
    public const PRIMARY = 'primary';

    public const UPGRADE_COMPATIBILITY = 'upgrade_compatibility';

    public const PREVIOUS_CERTIFIED = 'previous_certified';

    public const NOT_CERTIFIED = 'not_certified';

    public const EXACT_VERSION_PENDING = 'exact_version_pending';

    public function __construct(
        public string $category,
        public ?string $magentoVersion,
        public ?string $phpVersion,
    ) {}

    public function hasExactVersions(): bool
    {
        return filled($this->magentoVersion) && filled($this->phpVersion);
    }

    public function isCertified(): ?bool
    {
        return match ($this->category) {
            self::PRIMARY, self::UPGRADE_COMPATIBILITY, self::PREVIOUS_CERTIFIED => true,
            self::NOT_CERTIFIED => false,
            default => null,
        };
    }
}
