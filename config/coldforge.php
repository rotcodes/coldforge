<?php

return [
    // Fallback Brevo key for a Brevo sender whose own api_key is blank (used by the seeded sender).
    'brevo_api_key' => env('BREVO_API_KEY'),

    // Default per-campaign daily cap shown in the wizard.
    'default_daily_cap' => (int) env('COLDFORGE_DAILY_CAP', 50),

    // Max sends per second per sender (Brevo allows ~10/sec).
    'sender_tps' => (int) env('COLDFORGE_SENDER_TPS', 10),

    // Postal address shown in the unsubscribe footer (CAN-SPAM). Leave blank to omit.
    'footer_address' => env('COLDFORGE_FOOTER_ADDRESS', ''),

    // Random path secret for the Brevo webhook URL. Brevo doesn't sign webhooks,
    // so the secret in the URL path is the authentication layer. Generate via
    // `php artisan tinker --execute="echo bin2hex(random_bytes(24));"`
    'brevo_webhook_secret' => env('BREVO_WEBHOOK_SECRET'),

    // Same path-secret pattern for Hostinger Agentic Mail's message.received webhook.
    'hostinger_mail_webhook_secret' => env('HOSTINGER_MAIL_WEBHOOK_SECRET'),

    // The "Secret key" Hostinger shows once when the webhook is created. Hostinger sends it
    // as `Authorization: Bearer <secret>` on every delivery; when set, requests without it get 401.
    'hostinger_mail_webhook_token' => env('HOSTINGER_MAIL_WEBHOOK_TOKEN'),

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
