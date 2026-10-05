<?php

// MANAGED-1 (RFC-0029) — the managed portal's support contact, shown on every page. The phone is the one on our
// receipts and invoices to PTAA. Change either in .env without a deploy (config is not cached on this install).
return [
    'support_email' => env('MANAGED_SUPPORT_EMAIL', 'support@levelupgrowth.io'),
    'support_phone' => env('MANAGED_SUPPORT_PHONE', '+63 966 334 3422'),
];
