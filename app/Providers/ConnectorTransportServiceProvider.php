<?php

namespace App\Providers;

use App\Support\Connectors\Transport\ConnectorDestinationResolver;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorRequestSender;
use App\Support\Connectors\Transport\Curl\CurlClientFactory;
use App\Support\Connectors\Transport\Curl\DefaultCurlClientFactory;
use App\Support\Connectors\Transport\Dns\DefaultDnsChildProcessFactory;
use App\Support\Connectors\Transport\Dns\DnsChildProcessFactory;
use App\Support\Connectors\Transport\Dns\DnsResolver;
use App\Support\Connectors\Transport\Dns\DnsResponseParser;
use App\Support\Connectors\Transport\Dns\ProcessIsolatedDnsResolver;
use App\Support\Connectors\Transport\Dns\SystemDnsResolver;
use App\Support\Connectors\Transport\Internal\ConnectorDestinationResolverImpl;
use App\Support\Connectors\Transport\Internal\ConnectorRequestSenderImpl;
use App\Support\Connectors\Transport\SsrfSafeConnectorHttpTransport;
use App\Support\Connectors\Transport\TransportConfigurationException;
use App\Support\Connectors\Transport\TransportConfigurationFailureReason;
use Illuminate\Support\ServiceProvider;

class ConnectorTransportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DnsChildProcessFactory::class, DefaultDnsChildProcessFactory::class);
        $this->app->singleton(DnsResolver::class, function ($app) {
            try {
                return new ProcessIsolatedDnsResolver(
                    $app->make(DnsChildProcessFactory::class),
                    new DnsResponseParser,
                );
            } catch (TransportConfigurationException $exception) {
                if ($exception->reason !== TransportConfigurationFailureReason::UnsupportedPlatform) {
                    throw $exception;
                }

                return new SystemDnsResolver;
            }
        });
        $this->app->singleton(ConnectorDestinationResolver::class, ConnectorDestinationResolverImpl::class);
        $this->app->singleton(CurlClientFactory::class, DefaultCurlClientFactory::class);
        $this->app->singleton(ConnectorRequestSender::class, function ($app) {
            return new ConnectorRequestSenderImpl(
                $app->make(CurlClientFactory::class),
                verify: true,
            );
        });
        $this->app->singleton(ConnectorHttpTransport::class, function ($app) {
            return new SsrfSafeConnectorHttpTransport(
                $app->make(ConnectorDestinationResolver::class),
                $app->make(ConnectorRequestSender::class),
            );
        });
    }
}
