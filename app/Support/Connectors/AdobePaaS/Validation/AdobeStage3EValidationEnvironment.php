<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

final class AdobeStage3EValidationEnvironment
{
    public const string NAME = 'stage3e-validation';

    public static function isActive(): bool
    {
        return app()->environment(self::NAME);
    }
}
