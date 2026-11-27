<?php

namespace App\Enums;

enum MediaDiagnosisStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Attention = 'attention';
    case Failed = 'failed';
}
