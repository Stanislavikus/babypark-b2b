<?php

namespace App\Enums;

enum ConnectorProfileAvailability: string
{
    case Available = 'available';
    case ProfileNotFound = 'profile_not_found';
    case ProfileDisabled = 'profile_disabled';
    case CapabilityUnsupported = 'capability_unsupported';

    public function disabledReasonKey(): ?string
    {
        return match ($this) {
            self::Available => null,
            self::ProfileNotFound => 'connectors.ui.disabled_reasons.profile_not_found',
            self::ProfileDisabled => 'connectors.ui.disabled_reasons.profile_disabled',
            self::CapabilityUnsupported => 'connectors.ui.disabled_reasons.capability_unsupported',
        };
    }
}
