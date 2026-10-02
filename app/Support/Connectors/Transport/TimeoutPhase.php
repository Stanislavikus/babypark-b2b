<?php

namespace App\Support\Connectors\Transport;

enum TimeoutPhase
{
    case Connect;
    case Transfer;
    case Unknown;
}
