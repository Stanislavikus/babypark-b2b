<?php

namespace App\Enums;

enum ReceiveDiffState: string
{
    case Equal = 'equal';
    case Differs = 'differs';
    case RemoteAbsent = 'remote_absent';
    case LocalAbsent = 'local_absent';
    case UnsupportedOrBlocked = 'unsupported_or_blocked';
    case ExplicitClear = 'explicit_clear';
}
