<?php
// Copy to config.local.php (next to this file, outside public/) and fill in; never commit config.local.php.
return [
    'APP_URL' => 'https://pds.example.com',
    'DB_DSN' => 'mysql:host=localhost;dbname=cpaneluser_pdsync;charset=utf8mb4',
    'DB_USER' => 'cpaneluser_pdsync',
    'DB_PASS' => '',
    'GOOGLE_CLIENT_ID' => '',
    'GOOGLE_CLIENT_SECRET' => '',
    // Google accounts allowed to open the admin page, comma-separated (for example 'you@gmail.com').
    'ADMIN_EMAILS' => '',
    // Time zone for the admin page's daily usage counts.
    'TIMEZONE' => 'Asia/Manila',
    // Shown on the privacy notice as the contact for data requests (optional).
    'CONTACT_EMAIL' => '',
    // Optional standard analytics, next to the built-in counts on the admin page (see README, Usage statistics).
    // Umami: the script URL and website ID from your Umami dashboard, e.g. 'https://cloud.umami.is/script.js'.
    'UMAMI_SCRIPT' => '',
    'UMAMI_WEBSITE_ID' => '',
    // GoatCounter: your counting URL, e.g. 'https://yourname.goatcounter.com/count'.
    'GOATCOUNTER_URL' => '',
    // Link shown on the admin page, e.g. Umami's shared dashboard URL.
    'ANALYTICS_DASHBOARD_URL' => '',
];
