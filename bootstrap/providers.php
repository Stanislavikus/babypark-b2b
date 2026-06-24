<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\CabinetPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    CabinetPanelProvider::class,
];
