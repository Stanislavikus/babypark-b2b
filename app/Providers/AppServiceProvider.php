<?php

namespace App\Providers;

use App\Services\Connectors\ConnectorAccountPersistencePort;
use App\Services\Connectors\ConnectorAccountSettingsService;
use App\Services\Connectors\ConnectorDiscoveryDispatchPort;
use App\Services\Connectors\ConnectorDiscoveryRunDispatchService;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapabilityImpl;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapabilityImpl;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspaceMembership;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WorkspaceContext::class);
        $this->app->singleton(ConnectorProfileRegistry::class);
        $this->app->singleton(WorkspaceMembership::class);

        $this->app->bind(
            AdobePaaSConnectionCheckCapability::class,
            AdobePaaSConnectionCheckCapabilityImpl::class,
        );

        $this->app->bind(
            AdobePaaSDiscoveryCapability::class,
            AdobePaaSDiscoveryCapabilityImpl::class,
        );

        $this->app->bind(ConnectorAccountPersistencePort::class, ConnectorAccountSettingsService::class);
        $this->app->bind(ConnectorDiscoveryDispatchPort::class, ConnectorDiscoveryRunDispatchService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
