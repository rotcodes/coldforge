<?php

return [
    // Default per-campaign daily cap shown in the wizard.
    'default_daily_cap' => (int) env('COLDFORGE_DAILY_CAP', 50),

    'attachment_scanner' => [
        'binary' => env('CLAMAV_BINARY'),
        'bypass_local' => env('ATTACHMENT_SCAN_BYPASS', false),
    ],

    'retention' => [
        'message_bodies_days' => (int) env('RETENTION_MESSAGE_BODIES_DAYS', 365),
        'activity_days' => (int) env('RETENTION_ACTIVITY_DAYS', 180),
        'sync_logs_days' => (int) env('RETENTION_SYNC_LOGS_DAYS', 90),
        'ai_history_days' => (int) env('RETENTION_AI_HISTORY_DAYS', 180),
        'downloaded_attachments_days' => (int) env('RETENTION_ATTACHMENTS_DAYS', 90),
    ],

    // Default industries seeded on first run. Editable later from the Industries page.
    'default_industries' => [
        [
            'slug' => 'equipment_dealer',
            'label' => 'equipment dealership',
            'workflows' => 'equipment inquiries, sales follow-ups, parts requests, and service work across the dealership',
            'tracking' => 'customer inquiries, sales follow-ups, parts requests, service jobs, and customer history',
            'aliases' => ['equipment', 'construction equipment', 'heavy equipment', 'machinery dealer', 'equipment dealer'],
        ],
        [
            'slug' => 'steel_fabrication',
            'label' => 'steel fabrication shop',
            'workflows' => 'quote requests, material sourcing, production jobs, and delivery handoffs',
            'tracking' => 'quotes, materials, production jobs, and delivery status',
            'aliases' => ['steel fabrication', 'metal fabrication', 'stainless fabrication', 'fabrication', 'steel fabricator'],
        ],
        [
            'slug' => 'wholesale_distribution',
            'label' => 'wholesale distribution business',
            'workflows' => 'orders, inventory across warehouses, dispatch, and client follow-ups',
            'tracking' => 'orders, stock movement, dispatch status, and client accounts',
            'aliases' => ['wholesale', 'distribution', 'distributor', 'wholesaler'],
        ],
        [
            'slug' => 'warehouse',
            'label' => 'warehouse operation',
            'workflows' => 'inbound shipments, stock movement, picking, and dispatch across the warehouse',
            'tracking' => 'inbound/outbound, stock levels, picking lists, and dispatch status',
            'aliases' => ['warehouse', 'logistics', 'fulfillment', 'storage'],
        ],
        [
            'slug' => 'pos_retail',
            'label' => 'retail store',
            'workflows' => 'daily sales, stock levels, cashier shifts, and end-of-day reconciliation',
            'tracking' => 'sales, inventory, staff shifts, and daily reconciliation',
            'aliases' => ['retail', 'point of sale', 'pos', 'store', 'shop'],
        ],
        [
            'slug' => 'engineering_services',
            'label' => 'engineering services business',
            'workflows' => 'project estimates, resource scheduling, parts procurement, and site job tracking',
            'tracking' => 'projects, resources, parts, and site progress',
            'aliases' => ['engineering', 'consulting engineering', 'engineering services'],
        ],
        [
            'slug' => 'machining',
            'label' => 'machining shop',
            'workflows' => 'RFQs, drawing revisions, CNC jobs, and quality documentation',
            'tracking' => 'quotes, drawings, CNC jobs, and quality records',
            'aliases' => ['machining', 'cnc', 'swiss machining', 'precision machining', 'machine shop'],
        ],
        [
            'slug' => 'construction',
            'label' => 'construction business',
            'workflows' => 'bids, subcontractors, materials, and site progress',
            'tracking' => 'bids, subs, materials, and site progress',
            'aliases' => ['construction', 'contractor', 'general contractor', 'builder'],
        ],
        [
            'slug' => 'auto_parts',
            'label' => 'auto parts business',
            'workflows' => 'parts lookups, inventory, orders, and customer repairs',
            'tracking' => 'parts, inventory, orders, and customer history',
            'aliases' => ['auto parts', 'automotive parts', 'car parts', 'auto repair', 'automotive'],
        ],
        [
            'slug' => 'hvac',
            'label' => 'HVAC services business',
            'workflows' => 'service calls, dispatch, parts, and maintenance contracts',
            'tracking' => 'jobs, dispatch, parts, and maintenance schedules',
            'aliases' => ['hvac', 'heating cooling', 'air conditioning', 'refrigeration'],
        ],
    ],
];
