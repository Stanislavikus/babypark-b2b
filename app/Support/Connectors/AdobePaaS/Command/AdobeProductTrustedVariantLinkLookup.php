<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Models\ExternalRecordLink;

final class AdobeProductTrustedVariantLinkLookup
{
    public const STATUS_NONE = 'none';

    public const STATUS_TRUSTED = 'trusted';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    private function __construct(
        public readonly string $status,
        public readonly ?ExternalRecordLink $link = null,
    ) {}

    public static function none(): self
    {
        return new self(self::STATUS_NONE);
    }

    public static function trusted(ExternalRecordLink $link): self
    {
        return new self(self::STATUS_TRUSTED, $link);
    }

    public static function ambiguous(): self
    {
        return new self(self::STATUS_AMBIGUOUS);
    }

    public function isNone(): bool
    {
        return $this->status === self::STATUS_NONE;
    }

    public function isTrusted(): bool
    {
        return $this->status === self::STATUS_TRUSTED;
    }

    public function isAmbiguous(): bool
    {
        return $this->status === self::STATUS_AMBIGUOUS;
    }
}
