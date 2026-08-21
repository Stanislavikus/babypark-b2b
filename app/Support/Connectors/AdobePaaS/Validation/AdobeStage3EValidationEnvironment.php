<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

final class AdobeStage3EValidationEnvironment
{
    public const string ENVIRONMENT_NAME = 'stage3e-validation';

    public static function isActive(): bool
    {
        return app()->environment(self::ENVIRONMENT_NAME);
    }

    public static function assertActive(): void
    {
        if (! self::isActive()) {
            throw new AdobeStage3EValidationAbortedException(
                'Stage 3E validation is only available when APP_ENV='.self::ENVIRONMENT_NAME.'.',
            );
        }
    }
}
