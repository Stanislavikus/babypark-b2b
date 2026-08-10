<?php

namespace App\Support\Connectors\Integrations;

use App\Enums\ConnectorDefinitionStatus;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspace\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Deliberate merchant-safe read path for platforms eligible on Інтеграції (§9).
 *
 * Not PlatformAdminAuthorization (Layer D CRUD) and not inferred from
 * ConnectorAccountPolicy alone (which only covers existing accounts).
 */
final class EligibleConnectorPlatformCatalog
{
    public function __construct(
        private readonly WorkspaceMembership $workspaceMembership,
    ) {}

    /**
     * @return Collection<int, EligibleConnectorPlatform>
     */
    public function forWorkspace(User $actor, Workspace $workspace): Collection
    {
        if (! $this->workspaceMembership->belongs($actor, $workspace)) {
            throw new AuthorizationException('Actor does not belong to the workspace.');
        }

        // Reachability ceiling: any role that may view connector accounts may
        // see the eligible-platform catalog. Catalog rows carry no secrets.
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
            ->map(fn (ConnectorDefinition $definition): EligibleConnectorPlatform => new EligibleConnectorPlatform(
                id: $definition->id,
                code: $definition->code,
                name: $definition->name,
                status: $definition->status,
            ))
            ->values();
    }
}
