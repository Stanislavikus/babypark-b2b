<?php

use App\Providers\AppServiceProvider;
use App\Providers\ConnectorTransportServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\CabinetPanelProvider;

return [
    AppServiceProvider::class,
    ConnectorTransportServiceProvider::class,
    AdminPanelProvider::class,
    CabinetPanelProvider::class,
];
