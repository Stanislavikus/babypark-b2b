<?php

namespace App\Enums;

enum ReceiveDomainRoute: string
{
    case DynamicField = 'dynamic_field';
    case ProductVariantColumn = 'product_variant_column';
    case Pricing = 'pricing';
    case Availability = 'availability';
    case Media = 'media';
    case Relation = 'relation';
    case Unsupported = 'unsupported';
}
