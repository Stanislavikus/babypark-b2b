<?php

namespace App\Enums;

enum InventoryRecordSourceType: string
{
    case ManualAdjustment = 'manual_adjustment';
    case BulkImport = 'bulk_import';
    case ConnectorSync = 'connector_sync';
    case OrderAllocation = 'order_allocation';
}
