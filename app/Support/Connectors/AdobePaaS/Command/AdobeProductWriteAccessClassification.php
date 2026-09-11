<?php

namespace App\Support\Connectors\AdobePaaS\Command;

enum AdobeProductWriteAccessClassification: string
{
    case PermissionDenied = 'permission_denied';
    case AccessRejectedUndetermined = 'access_rejected_undetermined';
}
