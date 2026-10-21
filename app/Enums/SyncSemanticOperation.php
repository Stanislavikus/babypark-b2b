<?php

namespace App\Enums;

enum SyncSemanticOperation: string
{
    case Import = 'import';
    case Export = 'export';
}
