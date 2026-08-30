<?php

namespace App\Providers;

use App\Services\Connectors\ConnectorAccountPersistencePort;
use App\Services\Connectors\ConnectorAccountSettingsService;
use App\Services\Connectors\ConnectorDiscoveryDispatchPort;
use App\Services\Connectors\ConnectorDiscoveryRunDispatchService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapabilityImpl;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapabilityImpl;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersistence;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersister;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductOwnershipTrustPolicy;
use App\Support\Connectors\AdobePaaS\Command\ConservativeAdobeProductOwnershipTrustPolicy;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncHandshakeProbe;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncHandshakeProbeCapability;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspaceMembership;
use Filament\Actions\Action;
use Filament\Schemas\Components\Form as SchemaForm;
use Filament\Tables\Table;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AdobeSafeSyncHandshakeProbeCapability::class, AdobeSafeSyncHandshakeProbe::class);
        $this->app->singleton(WorkspaceContext::class);
        $this->app->singleton(ConnectorProfileRegistry::class);
        $this->app->singleton(WorkspaceMembership::class);
        $this->app->singleton(WorkspaceAuthorization::class);

        $this->app->bind(
            AdobePaaSConnectionCheckCapability::class,
            AdobePaaSConnectionCheckCapabilityImpl::class,
        );

        $this->app->bind(
            AdobePaaSDiscoveryCapability::class,
            AdobePaaSDiscoveryCapabilityImpl::class,
        );

        $this->app->bind(
            AdobeProductExternalRecordLinkPersistence::class,
            AdobeProductExternalRecordLinkPersister::class,
        );

        $this->app->bind(
            AdobeProductOwnershipTrustPolicy::class,
            ConservativeAdobeProductOwnershipTrustPolicy::class,
        );

        $this->app->bind(ConnectorAccountPersistencePort::class, ConnectorAccountSettingsService::class);
        $this->app->bind(ConnectorDiscoveryDispatchPort::class, ConnectorDiscoveryRunDispatchService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Preserve Filament 3 live filter behavior (Filament 4 defers filters by default).
        Table::configureUsing(
            fn (Table $table): Table => $table->deferFilters(false),
        );

        // Architecture mandate: disable native browser constraint validation on
        // Filament schema/page forms and action/modal submission forms.
        SchemaForm::configureUsing(
            fn (SchemaForm $form): SchemaForm => $form->extraAttributes(['novalidate' => true], merge: true),
        );

        Action::configureUsing(
            fn (Action $action): Action => $action->extraModalWindowAttributes(['novalidate' => true], merge: true),
        );
    }
}
