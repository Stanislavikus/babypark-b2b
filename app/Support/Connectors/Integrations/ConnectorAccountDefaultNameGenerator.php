<?php

namespace App\Support\Connectors\Integrations;

use App\Models\ConnectorAccount;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Collision-safe default names against active_name_uniqueness_key (§4).
 */
final class ConnectorAccountDefaultNameGenerator
{
    public function generate(
        Workspace $workspace,
        string $connectorDefinitionId,
        string $platformDisplayName,
    ): string {
        $existingNames = ConnectorAccount::query()
            ->where('workspace_id', $workspace->id)
            ->where('connector_definition_id', $connectorDefinitionId)
            ->pluck('name')
            ->map(fn (string $name): string => mb_strtolower($name))
            ->all();

        $existing = Collection::make($existingNames);

        $base = trim($platformDisplayName);

        if ($base === '') {
            $base = 'Підключення';
        }

        if (! $existing->contains(mb_strtolower($base))) {
            return $base;
        }

        for ($suffix = 2; $suffix < 10_000; $suffix++) {
            $candidate = $base.' — '.$suffix;

            if (! $existing->contains(mb_strtolower($candidate))) {
                return $candidate;
            }
        }

        return $base.' — '.uniqid();
    }
}
