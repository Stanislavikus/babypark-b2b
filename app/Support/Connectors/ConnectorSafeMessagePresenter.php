<?php

namespace App\Support\Connectors;

use Illuminate\Support\Facades\Lang;

final class ConnectorSafeMessagePresenter
{
    public const FALLBACK_KEY = 'connectors.errors.connection_check_failed';

    private const ALLOWED_PREFIX = 'connectors.errors.';

    /**
     * @param  array<string, scalar|null>|null  $parameters
     */
    public function present(?string $messageKey, ?array $parameters = null): string
    {
        if (! $this->isAllowedKey($messageKey)) {
            return __(self::FALLBACK_KEY);
        }

        return __($messageKey, $parameters ?? []);
    }

    public function isAllowedKey(?string $messageKey): bool
    {
        if ($messageKey === null || $messageKey === '') {
            return false;
        }

        if (! str_starts_with($messageKey, self::ALLOWED_PREFIX)) {
            return false;
        }

        return Lang::has($messageKey);
    }
}
