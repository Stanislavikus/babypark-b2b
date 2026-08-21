<?php

use App\Support\Connectors\AdobePaaS\Validation\AdobeStage3EValidationCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (app()->environment('stage3e-validation')) {
    Artisan::registerCommand(app(AdobeStage3EValidationCommand::class));
}
