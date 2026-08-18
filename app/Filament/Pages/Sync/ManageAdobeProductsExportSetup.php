<?php

namespace App\Filament\Pages\Sync;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\AdobeProductExportSetupAuthorizationService;
use App\Services\Sync\ConnectorAccountLayerBSetupProjectionQuery;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Sync\AdobeProductExportSetup\AdobeProductExportSetupSafeMessagePresenter;
use App\Support\Sync\Exceptions\ConnectorExecutionConfigurationValidationException;
use App\Support\Workspace\Rbac\Concerns\RequiresFreshWorkspaceSyncConfigurationPermission;
use App\Support\Workspace\WorkspaceContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Throwable;

class ManageAdobeProductsExportSetup extends Page implements HasForms
{
    use InteractsWithForms;
    use RequiresFreshWorkspaceSyncConfigurationPermission;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'sync-data-setup/{account}/products/export';

    protected string $view = 'filament.pages.sync.manage-adobe-products-export-setup';

    #[Locked]
    public string $accountId;

    public string $platformName = '';

    public string $accountName = '';

    public bool $setupUsable = true;

    public bool $setupRequired = true;

    public bool $configuredSetStale = false;

    public bool $configurationPaused = false;

    public bool $metadataUnavailable = false;

    public ?string $configuredAttributeSetName = null;

    /** @var array<string, string> */
    public array $attributeSetOptions = [];

    public ?array $data = [];

    public static function canAccess(array $parameters = []): bool
    {
        $user = Auth::user();
        $workspace = app(WorkspaceContext::class)->current();

        return $user instanceof User
            && app(AdobeProductExportSetupAuthorizationService::class)->canAccess($user, $workspace);
    }

    public function getTitle(): string|Htmlable
    {
        return __('sync_data_setup.adobe_products_export.title');
    }

    public function mount(string $account): void
    {
        $workspace = $this->resolveSyncSetupWorkspace();
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $this->accountId = $account;
        $this->refreshReadModel($user, $workspace);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('sync_data_setup.adobe_products_export.section'))
                    ->schema([
                        Select::make('attribute_set_id')
                            ->label(__('sync_data_setup.adobe_products_export.attribute_set_label'))
                            ->options(fn (): array => $this->attributeSetOptions)
                            ->required()
                            ->native(false)
                            ->disabled(fn (): bool => ! $this->setupUsable || $this->metadataUnavailable),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $workspace = $this->resolveSyncSetupWorkspace();

        if (! app(AdobeProductExportSetupAuthorizationService::class)->canAccess($user, $workspace)) {
            abort(403);
        }

        if (! $this->setupUsable || $this->metadataUnavailable) {
            abort(403);
        }

        $attributeSetId = (int) ($this->data['attribute_set_id'] ?? 0);

        try {
            app(AdobeProductExportSetupAuthorizationService::class)->configureAttributeSet(
                $user,
                $workspace->id,
                $this->accountId,
                $attributeSetId,
            );

            Notification::make()
                ->title(__('sync_data_setup.notifications.saved'))
                ->success()
                ->send();

            $this->refreshReadModel($user, $workspace);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ConnectorExecutionConfigurationValidationException $exception) {
            $this->notifyFailure($exception);
        } catch (Throwable $exception) {
            $this->notifyFailure($exception);
        }
    }

    private function refreshReadModel(User $user, Workspace $workspace): void
    {
        try {
            $readModel = app(AdobeProductExportSetupAuthorizationService::class)->projectReadModel(
                $user,
                $workspace->id,
                $this->accountId,
            );
        } catch (AuthorizationException) {
            abort(403);
        } catch (Throwable $exception) {
            Log::warning('sync_data_setup.read_model_failed', [
                'workspace_id' => $workspace->id,
                'connector_account_id' => $this->accountId,
                'exception' => $exception,
            ]);

            if (app(AdobeProductExportSetupAuthorizationService::class)->canAccess($user, $workspace)) {
                $projection = app(ConnectorAccountLayerBSetupProjectionQuery::class)
                    ->resolve($workspace->id, $this->accountId);

                if ($projection !== null) {
                    $this->platformName = $projection->platformName;
                    $this->accountName = $projection->accountName;
                    $this->setupUsable = $projection->setupUsable;
                }
            }

            $this->applyUnavailableReadState($exception);

            return;
        }

        $this->metadataUnavailable = false;
        $this->platformName = $readModel->account->platformName;
        $this->accountName = $readModel->account->accountName;
        $this->setupUsable = $readModel->account->setupUsable;
        $this->setupRequired = $readModel->setupRequired;
        $this->configuredSetStale = $readModel->configuredSetStale;
        $this->configurationPaused = $readModel->configurationPaused;
        $this->configuredAttributeSetName = $readModel->configuredAttributeSetName;
        $this->attributeSetOptions = $this->mapAttributeSetOptions($readModel->availableAttributeSets);

        $selectedId = $readModel->configuredAttributeSetId
            ?? $readModel->preselectedAttributeSetId;

        $this->form->fill([
            'attribute_set_id' => $selectedId !== null ? (string) $selectedId : null,
        ]);
    }

    private function applyUnavailableReadState(Throwable $exception): void
    {
        $presenter = app(AdobeProductExportSetupSafeMessagePresenter::class);

        $this->metadataUnavailable = true;
        $this->attributeSetOptions = [];
        $this->setupRequired = true;
        $this->configuredSetStale = false;
        $this->configurationPaused = false;
        $this->configuredAttributeSetName = null;

        Notification::make()
            ->title(__('sync_data_setup.errors.unavailable'))
            ->body($presenter->present($exception))
            ->danger()
            ->send();
    }

    /**
     * @param  list<array{attribute_set_id: int, attribute_set_name: string}>  $attributeSets
     * @return array<string, string>
     */
    private function mapAttributeSetOptions(array $attributeSets): array
    {
        $options = [];

        foreach ($attributeSets as $attributeSet) {
            $options[(string) $attributeSet['attribute_set_id']] = $attributeSet['attribute_set_name'];
        }

        return $options;
    }

    private function notifyFailure(Throwable $exception): void
    {
        if (! $exception instanceof ConnectorExecutionConfigurationValidationException
            && ! $exception instanceof ConnectorTransportException) {
            Log::warning('sync_data_setup.save_failed', [
                'connector_account_id' => $this->accountId,
                'exception' => $exception,
            ]);
        }

        $presenter = app(AdobeProductExportSetupSafeMessagePresenter::class);

        Notification::make()
            ->title(__('sync_data_setup.notifications.failed'))
            ->body($presenter->present($exception))
            ->danger()
            ->send();
    }
}
