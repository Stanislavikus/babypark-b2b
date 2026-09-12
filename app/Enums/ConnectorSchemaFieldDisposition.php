<?php

namespace App\Enums;

enum ConnectorSchemaFieldDisposition: string
{
    case CanonicalPlatform = 'canonical_platform';
    case ProviderStandard = 'provider_standard';
    case WorkspaceCustom = 'workspace_custom';
    case SystemOrDedicatedOwner = 'system_or_dedicated_owner';
    case ReviewNeeded = 'review_needed';
    case Unsupported = 'unsupported';
}
