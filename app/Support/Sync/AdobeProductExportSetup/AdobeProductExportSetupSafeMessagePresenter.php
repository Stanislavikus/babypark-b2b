<?php

namespace App\Support\Sync\AdobeProductExportSetup;

use App\Support\Connectors\Exceptions\ConnectorAccountNotFoundException;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Sync\Exceptions\ConnectorExecutionConfigurationValidationException;
use Throwable;

final class AdobeProductExportSetupSafeMessagePresenter
{
    public function present(Throwable $exception): string
    {
        if ($exception instanceof ConnectorExecutionConfigurationValidationException) {
            return __('sync_data_setup.errors.invalid_selection');
        }

        if ($exception instanceof ConnectorAccountNotFoundException) {
            return __('sync_data_setup.errors.unavailable');
        }

        if ($exception instanceof ConnectorTransportException) {
            return __('sync_data_setup.errors.remote_unavailable');
        }

        return __('sync_data_setup.errors.unavailable');
    }
}
