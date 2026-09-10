<?php

namespace App\Enums;

enum ConnectorComponentReadiness: string
{
    case Ready = 'ready';
    case SetupRequired = 'setup_required';
    case UpdateRequired = 'update_required';
}
