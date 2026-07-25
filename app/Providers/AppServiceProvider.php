<?php

namespace App\Providers;

use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapabilityImpl;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
