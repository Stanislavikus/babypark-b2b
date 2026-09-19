<?php

namespace App\Support\Connectors\Integrations;

use App\Enums\ConnectorDefinitionStatus;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Connectors\ConnectorProfileRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Deliberate merchant-safe read path for platforms eligible on Інтеграції (§9).
 *
 * Not PlatformAdminAuthorization (Layer D CRUD) and not inferred from
 * ConnectorAccountPolicy alone (which only covers existing accounts).
 *
 * Active alone is insufficient: an Active definition with zero accounts is
 * visible only when ConnectorProfileRegistry exposes an enabled AccountSetup
 * profile for that definition code. Інтеграції is not a roadmap/catalog of
 * future platforms.
 */
final class EligibleConnectorPlatformCatalog
{
    public function __construct(
        private readonly ConnectorProfileRegistry $profileRegistry,
    ) {}

    /**
     * @return Collection<int, EligibleConnectorPlatform>
     */
    public function forWorkspace(User $actor, Workspace $workspace): Collection
    {
        if (! Gate::forUser($actor)->allows('viewAny', ConnectorAccount::class)) {
            throw new AuthorizationException('Actor cannot view integrations.');
        }

        $definitionIdsWithAccounts = ConnectorAccount::query()
            ->where('workspace_id', $workspace->id)
            ->select('connector_definition_id')
            ->distinct()
            ->pluck('connector_definition_id');

        return ConnectorDefinition::query()
            ->select(['id', 'code', 'name', 'status'])
            ->where(function ($query) use ($definitionIdsWithAccounts): void {
                $query->where('status', ConnectorDefinitionStatus::Active);

                if ($definitionIdsWithAccounts->isNotEmpty()) {
                    $query->orWhere(function ($deprecatedWithAccounts) use ($definitionIdsWithAccounts): void {
                        $deprecatedWithAccounts
                            ->where('status', ConnectorDefinitionStatus::Deprecated)
                            ->whereIn('id', $definitionIdsWithAccounts);
                    });
                }
            })
            ->orderBy('name')
            ->get()
            ->filter(function (ConnectorDefinition $definition) use ($definitionIdsWithAccounts): bool {
                $hasAccount = $definitionIdsWithAccounts->contains($definition->id);

                if ($definition->status === ConnectorDefinitionStatus::Deprecated) {
                    return $hasAccount;
                }

                if ($definition->status !== ConnectorDefinitionStatus::Active) {
                    return false;
                }

                if ($hasAccount) {
                    return true;
                }

                return $this->profileRegistry->resolveAccountSetupProfile($definition->code) !== null;
            })
            ->map(fn (ConnectorDefinition $definition): EligibleConnectorPlatform => new EligibleConnectorPlatform(
                id: $definition->id,
                code: $definition->code,
                name: $definition->name,
                status: $definition->status,
            ))
            ->values();
    }
}
