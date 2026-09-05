<?php

namespace App\Filament\Pages\Integrations;

use App\Enums\ConnectorDefinitionStatus;
use App\Filament\Resources\ConnectorAccountResource;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;
use App\Models\User;
use App\Services\Connectors\ConnectorAccountSettingsService;
use App\Services\Connectors\CreateConnectorAccountInput;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\CredentialMutation;
use App\Support\Connectors\Exceptions\ConnectorAccountNameConflict;
use App\Support\Connectors\Exceptions\ConnectorAccountSettingsValidationException;
use App\Support\Connectors\Integrations\ConnectorAccountDefaultNameGenerator;
use App\Support\Connectors\Integrations\EligibleConnectorPlatformCatalog;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Workspace\WorkspaceContext;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ConnectPlatformIntegration extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'integrations/{platform}/connect';

    protected string $view = 'filament.pages.integrations.connect';

    public string $platform = '';

    public string $platformName = '';

    public string $generatedName = '';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        $workspace = app(WorkspaceContext::class)->current();

        return Gate::forUser($user)->allows('create', [ConnectorAccount::class, $workspace]);
    }

    public function getTitle(): string|Htmlable
    {
        return __('connectors.ui.integrations.connect.title', [
            'platform' => $this->platformName !== '' ? $this->platformName : $this->platform,
        ]);
    }

    public function mount(
        string $platform,
        EligibleConnectorPlatformCatalog $catalog,
        WorkspaceContext $workspaceContext,
        ConnectorProfileRegistry $profileRegistry,
        ConnectorAccountDefaultNameGenerator $nameGenerator,
    ): void {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $workspace = $workspaceContext->current();
        abort_unless(
            Gate::forUser($user)->allows('create', [ConnectorAccount::class, $workspace]),
            403,
        );

        $eligible = $catalog->forWorkspace($user, $workspace);
        $platformProjection = $eligible->first(
            fn ($item): bool => $item->code === $platform,
        );

        abort_if($platformProjection === null, 404);
        abort_unless($platformProjection->allowsNewConnections(), 404);
        abort_if($profileRegistry->resolveAccountSetupProfile($platform) === null, 404);

        $definition = ConnectorDefinition::query()
            ->where('code', $platform)
            ->where('status', ConnectorDefinitionStatus::Active)
            ->firstOrFail();

        $this->platform = $platform;
        $this->platformName = $platformProjection->name;
        $this->generatedName = $nameGenerator->generate(
            $workspace,
            $definition->id,
            $platformProjection->name,
        );

        $this->form->fill([
            'base_url' => '',
            'store_code' => 'default',
            'tenant_context' => null,
            'consumer_key' => '',
            'consumer_secret' => '',
            'access_token' => '',
            'access_token_secret' => '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('connectors.ui.integrations.connect.section_connection'))
                    ->description(__('connectors.ui.integrations.connect.section_description', [
                        'platform' => $this->platformName,
                    ]))
                    ->schema([
                        TextInput::make('base_url')
                            ->label(__('connectors.ui.integrations.connect.fields.store_url'))
                            ->required()
                            ->url()
                            ->maxLength(255),
                        TextInput::make('store_code')
                            ->label(__('connectors.ui.integrations.connect.fields.store_code'))
                            ->required()
                            ->maxLength(64),
                        TextInput::make('tenant_context')
                            ->label(__('connectors.ui.integrations.connect.fields.tenant_context'))
                            ->maxLength(255),
                        TextInput::make('consumer_key')
                            ->label(__('connectors.ui.integrations.connect.fields.consumer_key'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('consumer_secret')
                            ->label(__('connectors.ui.integrations.connect.fields.consumer_secret'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('access_token')
                            ->label(__('connectors.ui.integrations.connect.fields.access_token'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('access_token_secret')
                            ->label(__('connectors.ui.integrations.connect.fields.access_token_secret'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->maxLength(255),
                    ]),
            ])
            ->statePath('data');
    }

    public function connect(
        ConnectorAccountSettingsService $settingsService,
        WorkspaceContext $workspaceContext,
        ConnectorProfileRegistry $profileRegistry,
    ): void {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $workspace = $workspaceContext->current();
        abort_unless(
            Gate::forUser($user)->allows('create', [ConnectorAccount::class, $workspace]),
            403,
        );

        $data = $this->form->getState();

        $definition = ConnectorDefinition::query()
            ->where('code', $this->platform)
            ->where('status', ConnectorDefinitionStatus::Active)
            ->firstOrFail();

        $profile = $profileRegistry->resolveAccountSetupProfile($this->platform);
        abort_if($profile === null, 404);

        try {
            $result = $settingsService->create(
                $user,
                $workspace,
                CreateConnectorAccountInput::adobePaas(
                    connectorDefinitionId: $definition->id,
                    name: $this->generatedName,
                    baseUrl: (string) $data['base_url'],
                    storeCode: (string) $data['store_code'],
                    tenantContext: filled($data['tenant_context'] ?? null) ? (string) $data['tenant_context'] : null,
                    credentialMutation: CredentialMutation::replace(new OAuth1Credentials(
                        consumerKey: (string) $data['consumer_key'],
                        consumerSecret: (string) $data['consumer_secret'],
                        accessToken: (string) $data['access_token'],
                        accessTokenSecret: (string) $data['access_token_secret'],
                    )),
                ),
            );
        } catch (ConnectorAccountNameConflict) {
            Notification::make()
                ->title(__('connectors.ui.integrations.connect.name_conflict'))
                ->danger()
                ->send();

            return;
        } catch (ConnectorAccountSettingsValidationException $exception) {
            Notification::make()
                ->title(__('connectors.ui.integrations.connect.validation_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        } catch (Throwable) {
            Notification::make()
                ->title(__('connectors.ui.notifications.action_failed'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('connectors.ui.integrations.connect.success'))
            ->success()
            ->send();

        $this->redirect(ConnectorAccountResource::getUrl('view', ['record' => $result->id]));
    }
}
