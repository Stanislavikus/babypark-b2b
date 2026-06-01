<?php

namespace App\Enums;

enum SyncLogStatus: string
{
    case Success = 'success';
    case Error = 'error';
}
