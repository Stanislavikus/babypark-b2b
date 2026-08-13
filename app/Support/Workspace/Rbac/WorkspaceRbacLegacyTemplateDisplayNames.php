<?php

namespace App\Support\Workspace\Rbac;

final readonly class WorkspaceRbacLegacyTemplateDisplayNames
{
    public function __construct(
        public string $accessManagerDisplayName,
        public string $connectorDiscoveryDisplayName,
    ) {}

    public function forTemplateKey(string $templateKey): string
    {
        return match ($templateKey) {
            WorkspaceRbacLegacyTemplateKeys::ACCESS_MANAGER => $this->accessManagerDisplayName,
            WorkspaceRbacLegacyTemplateKeys::CONNECTOR_DISCOVERY_OPERATOR => $this->connectorDiscoveryDisplayName,
            default => throw new \InvalidArgumentException("Unknown legacy template key: {$templateKey}"),
        };
    }
}
