<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\Exceptions\ConnectorAccountSettingsValidationException;

final readonly class AdobePaaSStoreCode
{
    private const PATTERN = '/^[A-Za-z][A-Za-z0-9_]*$/';

    public function __construct(public string $value) {}

    public static function parse(string $raw): self
    {
        if ($raw === '' || preg_match(self::PATTERN, $raw) !== 1) {
            throw new ConnectorAccountSettingsValidationException(
                'Adobe PaaS store code must start with a letter and contain only letters, digits, and underscores.',
            );
        }

        return new self($raw);
    }
}
