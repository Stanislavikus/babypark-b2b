<?php

namespace App\Enums;

enum AdobeProductCategoryAssignmentState: string
{
    case PendingAdd = 'pending_add';
    case Managed = 'managed';
    case AddAmbiguous = 'add_ambiguous';
    case PendingRemove = 'pending_remove';
    case RemoveAmbiguous = 'remove_ambiguous';
}
