<?php

namespace App\Services\Pricing;

use App\Models\Workspace;
use LogicException;

final class WorkspaceTaxDefaults
{
    public function resolveWorkspaceRate(Workspace $workspace): float
    {
        if ($workspace->default_vat_rate === null) {
            throw new LogicException(
                "Workspace {$workspace->getKey()} has no default tax rate."
            );
        }

        return (float) $workspace->default_vat_rate;
    }

    public function resolveItemRate(float|string|null $itemRate, Workspace $workspace): float
    {
        return $itemRate !== null
            ? (float) $itemRate
            : $this->resolveWorkspaceRate($workspace);
    }
}
