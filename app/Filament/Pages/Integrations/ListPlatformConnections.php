<?php

namespace App\Filament\Pages\Integrations;

use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorDefinitionStatus;
use App\Filament\Resources\ConnectorAccountResource;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;
use App\Models\User;
use App\Support\Connectors\ConnectorAccountUiState;
use App\Support\Connectors\Integrations\ConnectorSetupProfileResolver;
use App\Support\Connectors\Integrations\EligibleConnectorPlatformCatalog;
use App\Support\Connectors\Integrations\IntegrationsStatusVocabulary;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ListPlatformConnections extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'integrations/{platform}/connections';

    protected string $view = 'filament.pages.integrations.platform-connections';

    public string $platform = '';

    public string $platformName = '';

    public bool $canConnectAnother = false;

    public ?string $connectAnotherUrl = null;

    public ?string $connectAnotherHint = null;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && Gate::forUser($user)->allows('viewAny', ConnectorAccount::class);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->platformName !== ''
            ? $this->platformName
            : __('connectors.ui.integrations.platform_connections.title');
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getTitle();
    }

    public function mount(
        string $platform,
        EligibleConnectorPlatformCatalog $catalog,
        WorkspaceContext $workspaceContext,
        ConnectorAccountUiState $uiState,
        IntegrationsStatusVocabulary $vocabulary,
        ConnectorSetupProfileResolver $setupProfileResolver,
    ): void {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $this->platform = $platform;
        $this->loadPageState(
            $user,
            $catalog,
            $workspaceContext,
            $uiState,
            $vocabulary,
            $setupProfileResolver,
            requireMultiAccount: true,
        );
    }

    public function refreshConnectionState(
        EligibleConnectorPlatformCatalog $catalog,
        WorkspaceContext $workspaceContext,
        ConnectorAccountUiState $uiState,
        IntegrationsStatusVocabulary $vocabulary,
        ConnectorSetupProfileResolver $setupProfileResolver,
    ): void {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $this->loadPageState(
            $user,
            $catalog,
            $workspaceContext,
            $uiState,
            $vocabulary,
            $setupProfileResolver,
            requireMultiAccount: false,
        );
    }

    protected function getHeaderActions(): array
    {
        if (! $this->canConnectAnother || $this->connectAnotherUrl === null) {
            return [];
        }

        return [
            Action::make('connectAnother')
                ->label(__('connectors.ui.integrations.actions.connect_another'))
                ->url($this->connectAnotherUrl),
        ];
    }

    private function loadPageState(
        User $user,
        EligibleConnectorPlatformCatalog $catalog,
        WorkspaceContext $workspaceContext,
        ConnectorAccountUiState $uiState,
        IntegrationsStatusVocabulary $vocabulary,
        ConnectorSetupProfileResolver $setupProfileResolver,
        bool $requireMultiAccount,
    ): void {
        $workspace = $workspaceContext->current();
        $eligible = $catalog->forWorkspace($user, $workspace);
        $platformProjection = $eligible->first(
            fn ($item): bool => $item->code === $this->platform,
        );

        abort_if($platformProjection === null, 404);

        $definition = ConnectorDefinition::query()
            ->where('code', $this->platform)
            ->firstOrFail();

        $this->platformName = $platformProjection->name;

        $accounts = ConnectorAccount::query()
            ->where('workspace_id', $workspace->id)
            ->where('connector_definition_id', $definition->id)
            ->with([
                'connectionChecks' => fn ($query) => $query
                    ->select(['id', 'connector_account_id', 'status'])
                    ->whereIn('status', [
                        ConnectorConnectionCheckStatus::Queued,
                        ConnectorConnectionCheckStatus::Running,
                    ]),
            ])
            ->orderBy('name')
            ->get();

        if ($requireMultiAccount) {
            abort_if($accounts->count() < 2, 404);
        }

        $this->rows = $accounts->map(function (ConnectorAccount $account) use ($uiState, $vocabulary): array {
            $activeCheck = $uiState->activeConnectionCheck($account);
            $runtimeLabel = $uiState->runtimeStatusLabel($activeCheck);
            // Stable label from UiState split (architecture reuse); page vocabulary for merchant label.
            $uiState->stableStatusLabel($account->connection_status);

            return [
                'id' => $account->id,
                'name' => $account->name,
                'status_label' => $vocabulary->labelFor($account->connection_status),
                'status_color' => $vocabulary->colorFor($account->connection_status),
                'runtime_overlay_label' => $runtimeLabel,
                'url' => ConnectorAccountResource::getUrl('view', ['record' => $account]),
            ];
        })->all();

        $canCreate = Gate::forUser($user)->allows('create', [ConnectorAccount::class, $workspace]);
        $setupAvailable = $setupProfileResolver->resolve($this->platform) !== null
            && $definition->status === ConnectorDefinitionStatus::Active;

        if (! $canCreate) {
            $this->canConnectAnother = false;
            $this->connectAnotherHint = __('connectors.ui.integrations.actions.ask_admin');
            $this->connectAnotherUrl = null;
        } elseif (! $setupAvailable) {
            $this->canConnectAnother = false;
            $this->connectAnotherHint = null;
            $this->connectAnotherUrl = null;
        } else {
            $this->canConnectAnother = true;
            $this->connectAnotherHint = null;
            $this->connectAnotherUrl = ConnectPlatformIntegration::getUrl(['platform' => $this->platform]);
        }
    }
}
