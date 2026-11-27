<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final class AdobeConfigurableParentSkuGenerator
{
    private const string IDENTITY_VERSION = 'adobe-configurable-parent:v1';

    public function generate(string $workspaceId, int $productId): string
    {
        $identityInput = self::IDENTITY_VERSION
            .'|'
            .$workspaceId
            .'|'
            .$productId;

        $digest = hash('sha256', $identityInput);

        return 'cfg-'.substr($digest, 0, 60);
    }
}
