<?php

return [
    'headline' => [
        'resolved' => 'Price found',
        'unavailable' => 'Price not found',
        'configuration_error' => 'Pricing settings need to be fixed',
    ],

    'outcome' => [
        'resolved' => 'A valid price was found for the selected customer.',
        'unavailable' => 'No available source returned a price.',
        'configuration_error' => 'The check cannot be completed due to a pricing configuration error.',
    ],

    'step_outcome' => [
        'used' => 'Used',
        'not_used' => 'Not used',
        'not_checked_resolved' => 'Not checked — price already resolved',
    ],

    'source' => [
        'customer_price_list' => 'Customer price list',
        'workspace_default_price_list' => 'Default price list',
        'base_price_cache' => 'Base price',
    ],

    'reason' => [
        'price_list_not_assigned' => 'Price list not assigned',
        'price_list_inactive' => 'Price list inactive',
        'item_missing' => 'Item not found',
        'item_inactive' => 'Item inactive',
        'quantity_below_minimum' => 'Quantity below minimum',
        'not_yet_effective' => 'Not yet effective',
        'expired' => 'Expired',
        'matched' => 'Matched',
        'previous_source_resolved' => 'Previous source already resolved price',
        'all_sources_exhausted' => 'All sources exhausted',
        'default_price_list_misconfigured' => 'Default price list misconfigured',
    ],

    'explanation' => [
        'customer_price_list' => [
            'price_list_not_assigned' => 'No price list assigned to the customer.',
            'price_list_inactive' => 'Assigned price list «:name» is inactive.',
            'item_missing' => 'No item for variant :sku in the customer price list.',
            'item_inactive' => 'Price list item is inactive (status: :status).',
            'quantity_below_minimum' => 'Minimum quantity for this item is :quantity pcs.',
            'not_yet_effective' => 'Price becomes effective from :date.',
            'expired' => 'Price was valid until :date.',
            'matched' => 'Found price :amount.',
        ],
        'workspace_default_price_list' => [
            'item_missing' => 'No item for variant :sku in the default price list.',
            'item_inactive' => 'Default price list item is inactive (status: :status).',
            'quantity_below_minimum' => 'Minimum quantity for this item is :quantity pcs.',
            'not_yet_effective' => 'Price becomes effective from :date.',
            'expired' => 'Price was valid until :date.',
            'matched' => 'Found price :amount.',
            'previous_source_resolved' => 'Price was already found at a previous step.',
            'default_price_list_misconfigured' => 'Workspace default price list is misconfigured.',
        ],
        'base_price_cache' => [
            'item_missing' => 'Base price is not set.',
            'matched' => 'Used base price :amount.',
            'previous_source_resolved' => 'Price was already found at a previous step.',
        ],
    ],

    'action' => [
        'extend_validity' => 'Extend validity',
        'add_item_to_customer_price_list' => 'Add item to customer price list',
        'add_item_to_default_price_list' => 'Add item to default price list',
        'assign_price_list' => 'Assign price list to customer',
        'open_price_list' => 'Open price list',
        'open_price_list_item' => 'Open price list item',
        'edit_price_list_item' => 'Edit this price list item',
        'open_product' => 'Open product',
        'check_quantity' => 'Check price for :quantity pcs.',
        'set_base_price' => 'Open variant and set base price',
        'open_price_list_settings' => 'Open price list settings',
    ],

    'section' => [
        'what_to_fix' => 'What needs to be fixed',
        'decision_path' => 'How the system checked the price',
        'technical_details' => 'Technical details',
        'copy_diagnostics' => 'Copy diagnostics',
        'copied' => 'Copied',
    ],

    'form' => [
        'check_price' => 'Check price',
        'parameters' => 'Check parameters',
        'customer' => 'Customer',
        'product_filter' => 'Product (filter)',
        'variant' => 'Variant',
        'quantity' => 'Quantity',
        'effective_at' => 'Price effective date/time',
        'timezone' => 'Timezone',
        'timezone_hint' => 'Timezone: :timezone',
        'price_checked' => 'Price checked',
    ],

    'page' => [
        'title' => 'Customer price check',
        'subheading' => 'Find out what price the customer gets and why.',
        'navigation' => 'Price check',
    ],

    'technical' => [
        'status' => 'Status',
        'reason_codes' => 'Reason codes',
        'failure' => 'Failure',
        'price' => 'Price (technical)',
        'context' => 'Context',
        'trace' => 'Trace',
        'trace_index' => '#',
        'trace_source' => 'Source',
        'trace_status' => 'Status',
        'trace_reason' => 'Reason',
        'trace_price_list_id' => 'price_list_id',
        'trace_amount' => 'Amount',
        'trace_metadata' => 'Metadata',
    ],

    'opens_in_new_tab' => '(opens in a new tab)',
];
