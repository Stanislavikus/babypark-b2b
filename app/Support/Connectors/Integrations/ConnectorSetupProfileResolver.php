<?php

namespace App\Support\Connectors\Integrations;

/**
 * Maps connector definition codes to setup-capable auth profiles.
 *
 * Presentation/setup wiring only — not a ConnectorCapability substitute.
 * Platforms without a mapped profile cannot offer Підключити yet.
 */
final class ConnectorSetupProfileResolver
{
    /**
     * @var array<string, string>
     */
    private const CODE_TO_PROFILE = [
        'adobe_commerce' => 'adobe_commerce_paas_oauth1_integration',
    ];

    public function resolve(string $connectorDefinitionCode): ?string
    {
        return self::CODE_TO_PROFILE[$connectorDefinitionCode] ?? null;
    }
}
