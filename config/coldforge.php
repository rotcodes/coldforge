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
];
