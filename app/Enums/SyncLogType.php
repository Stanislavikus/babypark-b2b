<?php

namespace App\Enums;

enum SyncLogType: string
{
    case Products = 'products';
    case Prices = 'prices';
    case Stocks = 'stocks';
    case Contractors = 'contractors';
    case Statuses = 'statuses';
}
